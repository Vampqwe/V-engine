<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Immutable DTO загруженного файла (Контракт §16.2, FileUpload P2).
 * Инкапсулирует данные одного элемента $_FILES без прямого доступа к суперглобалим
 * внутри модулей (статья о запрете superglobals вне точки входа).
 */
final class UploadedFile
{
    /**
     * @param string $tmpPath  путь во временном хранилище PHP (is_uploaded_file проверяется точкой входа)
     * @param string $name     исходное имя файла у клиента
     * @param string $mimeType MIME из отчёта клиента (НЕ доверенный — реальный тип определяет модуль)
     * @param int    $size     размер в байтах из отчёта клиента
     * @param int    $error    код UPLOAD_ERR_* из PHP
     */
    public function __construct(
        public readonly string $tmpPath,
        public readonly string $name,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly int $error,
    ) {
    }

    /** Базовое имя файла без пути (защита от directory traversal в имени). */
    public function safeName(): string
    {
        $base = basename(str_replace('\\', '/', $this->name));
        // Оставляем только безопасные символы.
        $clean = preg_replace('/[^A-Za-z0-9._-]/u', '_', $base) ?? 'file';
        // Двойная точка и зарезервированные имена — недопустимы.
        if ($clean === '' || $clean === '.' || $clean === '..' || str_contains($clean, '..')) {
            $clean = 'file_' . substr(md5($this->name), 0, 8);
        }
        return $clean;
    }

    public function hasError(): bool
    {
        return $this->error !== UPLOAD_ERR_OK;
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize directive.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE form directive.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
            default               => 'Unknown upload error.',
        };
    }

    /**
     * Построить из элемента массива $_FILES. Используется ТОЛЬКО точкой входа (public/index.php)
     * или адаптерами тестов; модули получают уже готовый DTO.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $spec
     */
    public static function fromFilesArray(array $spec): self
    {
        return new self(
            (string) ($spec['tmp_name'] ?? ''),
            (string) ($spec['name'] ?? ''),
            (string) ($spec['type'] ?? 'application/octet-stream'),
            (int) ($spec['size'] ?? 0),
            (int) ($spec['error'] ?? UPLOAD_ERR_NO_FILE),
        );
    }
}
