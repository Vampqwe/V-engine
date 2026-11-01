<?php
declare(strict_types=1);

namespace Core\Module\FileStorage;

use Core\Contract\FileStorageInterface;
use Core\Exception\FileStorageException;

/**
 * P0-модуль FileStorage (ШАГ 2, Статья 8.1).
 *
 * Единственный легальный слой работы с файловой системой движка.
 * Ключевые свойства безопасности:
 *  - Jailed-root: все относительные пути резолвятся строго внутри $rootDir;
 *    выход через ../, абсолютные пути вне root и симлинги наружу отклоняются;
 *  - атомарная запись (tmp + chmod + rename) — читатель никогда не увидит
 *    полурезультат записи (критично для логов, кеша, конфигов);
 *  - никаких side-effect конструкторов: создание объекта не трогает диск.
 */
final class FileStorage implements FileStorageInterface
{
    public const VERSION = '1.0.0';

    private function __construct(private readonly string $rootDir)
    {
    }

    /**
     * Factory-конструктор: root обязан существовать как каталог.
     * Симлинг root'а допустим, но дальше все проверки идут относительно
     * realpath(root) — инвариант «внутри real-root».
     */
    public static function create(string $rootDir): self
    {
        $real = realpath($rootDir);
        if ($real === false || !is_dir($real)) {
            throw new FileStorageException(sprintf('Storage root "%s" does not exist or is not a directory.', $rootDir));
        }
        // Windows-совместимость: realpath() на Windows возвращает смешанные
        // разделители ("H:\Server\.../config"). Приводим корень к канону '/' —
        // тот же вид, что даёт normalize() для entry-путей. Содержимое
        // сегментов не меняется (обратный слэш в имени файла на Windows запрещён).
        return new self(self::canonicalize($real));
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    /** Канонический вид пути: \ => / (сохраняя ведущий слэш), без обращения к ФС. */
    private static function canonicalize(string $path): string
    {
        if (!str_contains($path, '\\')) {
            return $path;
        }
        $leading = str_starts_with($path, '/') ? '/' : '';
        return $leading . str_replace('\\', '/', substr($path, strlen($leading)));
    }

    // =========================================================================
    // FileStorageInterface
    // =========================================================================

    public function load(string $path): ?string
    {
        $full = $this->resolve($path);
        if (!is_file($full) || !is_readable($full)) {
            return null;
        }
        $content = @file_get_contents($full);
        return $content === false ? null : $content;
    }

    public function save(string $path, string $content): bool
    {
        $full = $this->resolve($path, forWrite: true);
        $dir = dirname($full);
        if (!is_dir($dir) && !$this->createDir($dir)) {
            return false;
        }

        // Атомарная запись: пишем в tmp рядом и переименовываем поверх цели.
        $tmp = $full . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $written = @file_put_contents($tmp, $content, LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            return false;
        }
        // Права новой файла наследуются от целевого файла (или 0644 для нового).
        @chmod($tmp, is_file($full) ? (fileperms($full) & 0o7777) : 0o644);
        if (!@rename($tmp, $full)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    public function delete(string $path): bool
    {
        $full = $this->resolve($path, forWrite: true);
        if (is_dir($full)) {
            return @rmdir($full);
        }
        if (is_file($full) || is_link($full)) {
            return @unlink($full);
        }
        return false;
    }

    public function exists(string $path): bool
    {
        try {
            $full = $this->resolve($path);
        } catch (FileStorageException) {
            return false;
        }
        return file_exists($full);
    }

    public function createDir(string $path): bool
    {
        $full = $this->resolve($path, forWrite: true);
        if (is_dir($full)) {
            return true;
        }
        return @mkdir($full, 0o755, recursive: true) || is_dir($full);
    }

    public function list(string $pattern): array
    {
        if ($pattern === '') {
            return [];
        }
        // Шаблон всегда собирается внутри root (jail). Спецсимволы абсолютной
        // части паттерна не допускаем: относительный шаблон режем от root.
        if ($pattern[0] === '/') {
            $normalized = $this->normalize($pattern);
            if (!$this->isInsideRoot($normalized)) {
                throw new FileStorageException(sprintf('Pattern "%s" escapes storage root.', $pattern));
            }
            $pattern = ltrim(substr($normalized, strlen($this->rootDir)), '/');
        }

        // glob() здесь ненадёжен: его реализация glibc раскрывает «..» в шаблоне
        // (ловушка 'logs/../../etc/*' показывает файлы ВНЕ jail) и при этом
        // глоб не находит файлы, чьи имена начинаются с «..». Поэтому —
        // собственный сканер: сегменты шаблона сопоставляются построчно,
        // список строится от корня jail, наружу не выходим никогда.
        [$dirPattern, $namePattern] = self::splitPattern($pattern);
        $base = $this->rootDir . ($dirPattern === '' ? '' : '/' . $dirPattern);
        // Директория из шаблона не должна содержать magic-сегментов наружу.
        foreach (explode('/', $dirPattern) as $segment) {
            if ($segment === '..') {
                throw new FileStorageException(sprintf('Pattern "%s" escapes storage root.', $pattern));
            }
        }
        if (!is_dir($base)) {
            return [];
        }

        $results = [];
        $this->scanDir($base, $namePattern, $results);
        sort($results);
        return $results;
    }

    /** Разделить шаблон на директорию и имя файла. */
    private static function splitPattern(string $pattern): array
    {
        $pos = strrpos($pattern, '/');
        return $pos === false
            ? ['', $pattern]
            : [substr($pattern, 0, $pos), substr($pattern, $pos + 1)];
    }

    /** Рекурсивный обход одной директории (не глубже уровня шаблона). */
    private function scanDir(string $dir, string $namePattern, array &$results): void
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!self::fnmatchSafe($namePattern, $entry)) {
                continue;
            }
            $full = $dir . '/' . $entry;
            if (is_link($full)) {
                // Симлинги в выдачу не отдаём: их цель вне контроля jail-пути.
                continue;
            }
            if (!is_file($full)) {
                continue;
            }
            $results[] = $full;
        }
    }

    /**
     * fnmatch без флага GLOB_PATH_SEPARATORS: '*' не должен поглощать '/',
     * но шаблоны у нас уже разрезаны на «директория + имя», поэтому для имени
     * обычный fnmatch безопасен. Пустое имя (шаблон 'logs/') ничего не матчит.
     */
    private static function fnmatchSafe(string $pattern, string $subject): bool
    {
        return $pattern !== '' && fnmatch($pattern, $subject);
    }

    // =========================================================================
    // Path resolution (jail)
    // =========================================================================

    /**
     * Относительный путь => абсолютный внутри root. Абсолютный путь обязан
     * лежать внутри root. Бросает FileStorageException при выходе за jail.
     */
    public function resolve(string $path, bool $forWrite = false): string
    {
        if ($path === '') {
            throw new FileStorageException('Path must not be empty.');
        }

        if ($path[0] === '/') {
            $candidate = $path;
        } else {
            $candidate = $this->rootDir . '/' . $path;
        }
        $candidate = $this->normalize($candidate);

        // Для существующих целей проверяем реальный путь (защита от симлингов наружу).
        $existing = realpath($candidate) !== false ? self::canonicalize(realpath($candidate)) : false;
        if ($existing !== false) {
            $resolved = $existing;
        } else {
            // Цели нет (запись нового файла) — проверяем ближайший существующий предок.
            $resolved = $this->jailCheckNonExisting($candidate);
        }

        if (!$this->isInsideRoot($resolved)) {
            throw new FileStorageException(sprintf(
                'Path "%s" escapes storage root "%s".',
                $path,
                $this->rootDir,
            ));
        }
        return $resolved;
    }

    /** Путь относительно root (для debug/тестов). */
    public function relative(string $absolutePath): string
    {
        $prefix = $this->rootDir . '/';
        return str_starts_with($absolutePath, $prefix) ? substr($absolutePath, strlen($prefix)) : $absolutePath;
    }

    private function jailCheckNonExisting(string $candidate): string
    {
        $probe = $candidate;
        $tail = '';
        while (!is_file($probe) && !is_dir($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                break; // достигли корня ФС
            }
            $tail = basename($probe) . ($tail === '' ? '' : '/' . $tail);
            $probe = $parent;
        }
        $realRaw = realpath($probe);
        $real = $realRaw === false ? false : self::canonicalize($realRaw);
        if ($real === false) {
            throw new FileStorageException(sprintf('Cannot resolve path "%s".', $candidate));
        }
        if (!$this->isInsideRoot($real)) {
            throw new FileStorageException(sprintf('Parent of "%s" escapes storage root.', $candidate));
        }
        return $tail === '' ? $real : $real . '/' . $tail;
    }

    private function isInsideRoot(string $path): bool
    {
        return $path === $this->rootDir || str_starts_with($path, $this->rootDir . '/');
    }

    /** Убирает ., .., двойные слэши без обращения к ФС. */
    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return '/' . implode('/', $parts);
    }
}
