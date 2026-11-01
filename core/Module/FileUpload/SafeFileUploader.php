<?php
declare(strict_types=1);

namespace Core\Module\FileUpload;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\FileUploadInterface;
use Core\Contract\RequestInterface;
use Core\Contract\UploadedFile;
use Core\Contract\ValidationResult;
use Core\Exception\UploadException;

/**
 * Реализация FileUploadInterface (P2, §16.2).
 *
 * Модель безопасности:
 *  - клиентский MIME/имя/размер НЕ доверяются — всё перепроверяется сервером;
 *  - реальный тип определяется по содержимому через finfo;
 *  - запись только через FileStorageInterface (jailed-root, §20);
 *  - финальное имя генерируется сервером: {base}_{random}.{ext} с суффиксом при коллизии;
 *  - расширение из имени клиента берётся только после сверки с реальным MIME
 *    и белым списком конфига — двойной фильтр.
 */
final class SafeFileUploader implements FileUploadInterface
{
    public const VERSION = '1.0.0';

    /** Маппинг «реальный MIME → канонические расширения». Клиентский type игнорируется. */
    private const MIME_EXT_MAP = [
        'image/jpeg'          => ['jpg', 'jpeg'],
        'image/png'           => ['png'],
        'image/gif'           => ['gif'],
        'image/webp'          => ['webp'],
        'image/svg+xml'       => ['svg'],
        'application/pdf'     => ['pdf'],
        'text/plain'          => ['txt', 'md', 'csv'],
        'application/zip'     => ['zip'],
        'application/x-7z-compressed' => ['7z'],
        'application/gzip'    => ['gz'],
        'video/mp4'           => ['mp4'],
        'video/webm'          => ['webm'],
        'audio/mpeg'          => ['mp3'],
        'audio/ogg'           => ['ogg'],
    ];

    private const EXECUTABLE_INNER_EXT = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'htaccess'];

    /** Опасные реальные MIME: запрещены ВСЕГДА, независимо от белого списка конфига (§ безопасность). */
    private const FORBIDDEN_MIME = [
        'text/x-php', 'application/x-httpd-php', 'application/x-httpd-php-source',
        'application/x-php', 'text/php', 'application/x-sh', 'text/x-shellscript',
        'application/x-executable', 'application/x-sharedlib', 'application/x-dosexec',
        'application/x-msdownload', 'application/x-setuid', 'application/x-script',
    ];

    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly ConfigInterface $config,
    ) {
    }

    // =========================================================================
    // API контракта
    // =========================================================================

    public function store(UploadedFile $file, string $directory, ?array $overrides = null): string
    {
        $result = $this->validate($file, $overrides);
        if (!$result->isValid()) {
            $messages = $result->errorsFor('file');
            throw new UploadException($messages === [] ? 'Invalid upload.' : $messages[0]);
        }

        $dir = $this->normalizeDirectory($directory);
        $realMime = $this->detectMime($file);
        $ext = $this->pickExtension($file, $realMime);
        $finalName = $this->generateName($file, $ext);
        $relative = ltrim($dir !== '' ? $dir . '/' . $finalName : $finalName, '/');

        // Коллизия в jailed-root → добавляем счётчик.
        $candidate = $relative;
        for ($i = 1; $this->storage->exists($candidate); $i++) {
            $candidate = ltrim(($dir !== '' ? $dir . '/' : '') . $this->uniqueSuffix($finalName, $i, $ext), '/');
            if ($i > 50) {
                throw new UploadException('Too many name collisions in target directory.');
            }
        }

        $contents = $this->readTmp($file);
        if (!$this->storage->save($candidate, $contents)) {
            throw new UploadException('Failed to persist uploaded file into storage.');
        }

        return $candidate;
    }

    public function validate(UploadedFile $file, ?array $overrides = null): ValidationResult
    {
        $errors = [];

        if ($file->hasError()) {
            if ($file->error === UPLOAD_ERR_NO_FILE) {
                return new ValidationResult(['file' => ['No file was uploaded.']], []);
            }
            return new ValidationResult(['file' => [$file->errorMessage()]], []);
        }

        if ($file->tmpPath === '' || !is_readable($file->tmpPath)) {
            return new ValidationResult(['file' => ['Temporary upload file is missing or unreadable.']], []);
        }

        $maxSize = (int) ($overrides['max_size'] ?? $this->config->get('upload.max_size') ?? 5 * 1024 * 1024);
        $allowedExt = $this->toStringList($overrides['allowed_ext'] ?? $this->config->get('upload.allowed_ext'));
        $allowedMime = $this->toStringList($overrides['allowed_mime'] ?? $this->config->get('upload.allowed_mime'));

        // Размер: доверяем ТОЛЬКО реальному размеру файла на диске, не отчёту клиента.
        $realSize = $this->realSize($file);
        if ($realSize <= 0) {
            $errors['file'][] = 'Empty file is not accepted.';
        } elseif ($realSize > $maxSize) {
            $errors['file'][] = sprintf('File size %d bytes exceeds allowed limit %d bytes.', $realSize, $maxSize);
        }

        // Реальный MIME по содержимому.
        $realMime = $this->detectMime($file);
        // Абсолютный запрет исполняемого содержимого — даже если админ сайта ошибочно
        // включил его в белый список (defense-in-depth).
        foreach (self::FORBIDDEN_MIME as $forbidden) {
            if ($realMime === $forbidden || str_starts_with($realMime, $forbidden . '/')) {
                return new ValidationResult(['file' => [sprintf('Executable content type "%s" is absolutely forbidden.', $realMime)]], []);
            }
        }
        if ($allowedMime !== [] && !in_array($realMime, $allowedMime, true)) {
            $errors['file'][] = sprintf('Detected content type "%s" is not allowed.', $realMime);
        }

        // Расширение: из safeName(), белый список + согласование с реальным MIME.
        $clientExt = strtolower(pathinfo($file->safeName(), PATHINFO_EXTENSION));
        if ($clientExt === '') {
            $errors['file'][] = 'File must have an extension.';
        } else {
            if ($allowedExt !== [] && !in_array($clientExt, $allowedExt, true)) {
                $errors['file'][] = sprintf('Extension ".%s" is not allowed.', $clientExt);
            }
            $knownExts = self::MIME_EXT_MAP[$realMime] ?? [];
            if ($knownExts !== [] && !in_array($clientExt, $knownExts, true)) {
                $errors['file'][] = sprintf('Extension ".%s" contradicts detected content type "%s".', $clientExt, $realMime);
            }
            // Двойное расширение вида shell.php.jpg — запрещаем всегда.
            $base = pathinfo($file->safeName(), PATHINFO_FILENAME);
            if (str_contains($base, '.')) {
                $inner = strtolower(pathinfo($base, PATHINFO_EXTENSION));
                if (in_array($inner, self::EXECUTABLE_INNER_EXT, true)) {
                    $errors['file'][] = 'Double extension with executable segment is forbidden.';
                }
            }
        }

        return new ValidationResult($errors, $errors === [] ? ['mime' => $realMime, 'size' => $realSize] : []);
    }

    public function fromRequest(RequestInterface $request, string $field): ?UploadedFile
    {
        return $request->getUploadedFile($field);
    }

    // =========================================================================
    // Внутреннее
    // =========================================================================

    private function detectMime(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = @$finfo->file($file->tmpPath);
        return $detected === false ? 'application/octet-stream' : $detected;
    }

    private function realSize(UploadedFile $file): int
    {
        clearstatcache(true, $file->tmpPath);
        return (int) @filesize($file->tmpPath);
    }

    /**
     * Итоговое расширение: каноническое для РЕАЛЬНОГО MIME (первое из карты),
     * либо клиентское если MIME неизвестен.
     */
    private function pickExtension(UploadedFile $file, string $realMime): string
    {
        $clientExt = strtolower(pathinfo($file->safeName(), PATHINFO_EXTENSION));
        $mapped = self::MIME_EXT_MAP[$realMime] ?? [];
        if ($mapped !== []) {
            return in_array($clientExt, $mapped, true) ? $clientExt : $mapped[0];
        }
        return $clientExt;
    }

    private function generateName(UploadedFile $file, string $ext): string
    {
        $base = pathinfo($file->safeName(), PATHINFO_FILENAME);
        $base = substr(preg_replace('/[^A-Za-z0-9_-]/u', '_', $base) ?? 'file', 0, 60);
        if ($base === '' || $base === '.') {
            $base = 'file';
        }
        $random = bin2hex(random_bytes(4));
        return $ext === '' ? $base . '_' . $random : $base . '_' . $random . '.' . $ext;
    }

    private function uniqueSuffix(string $name, int $i, string $ext): string
    {
        $stem = $ext === '' ? $name : substr($name, 0, -(strlen($ext) + 1));
        return $ext === '' ? $stem . '__' . $i : $stem . '__' . $i . '.' . $ext;
    }

    private function normalizeDirectory(string $directory): string
    {
        $dir = trim(str_replace('\\', '/', $directory), '/');
        if ($dir === '') {
            return '';
        }
        foreach (explode('/', $dir) as $segment) {
            if (!preg_match('/^[A-Za-z0-9._-]+$/u', $segment) || $segment === '.' || $segment === '..') {
                throw new UploadException(sprintf('Invalid target directory segment "%s".', $segment));
            }
        }
        return $dir;
    }

    private function readTmp(UploadedFile $file): string
    {
        $contents = @file_get_contents($file->tmpPath);
        if ($contents === false) {
            throw new UploadException('Unable to read temporary upload file.');
        }
        return $contents;
    }

    /** @return list<string> */
    private function toStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            $s = strtolower(trim((string) $v));
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }
}
