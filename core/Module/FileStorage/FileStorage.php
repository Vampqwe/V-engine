<?php
declare(strict_types=1);

namespace Core\Module\FileStorage;

use Core\Contract\FileStorageInterface;
use Core\Contract\SiteContextInterface;

/**
 * P0-модуль FileStorage — единственный легальный способ работы с ФС (Статьи 16.1, 23).
 * Песочница: корень = папка сайта public/{site}/ (Статья 20). Нормализация путей,
 * проверка симлинков через realpath, атомарная запись (LOCK_EX/flock).
 */
final class FileStorage implements FileStorageInterface
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if ($resolved === false) {
            throw new \RuntimeException('Корень хранилища не существует: ' . $root);
        }
        $this->root = $resolved;
    }

    /** Фабрика от контекста сайта (Composition Root — Статья 11.1). */
    public static function fromContext(SiteContextInterface $context): self
    {
        return new self($context->getBaseFolder());
    }

    public function load(string $path): ?string
    {
        $full = $this->resolveExisting($path);
        if ($full === null || !is_file($full)) {
            return null;
        }
        $content = @file_get_contents($full);
        return $content === false ? null : $content;
    }

    public function save(string $path, string $content): bool
    {
        $full = $this->resolveForWrite($path);
        if ($full === null) {
            return false;
        }
        $dir = dirname($full);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return false;
        }
        // LOCK_EX — атомарность записи (Статья 31)
        return @file_put_contents($full, $content, LOCK_EX) !== false;
    }

    public function append(string $path, string $content): bool
    {
        $full = $this->resolveForWrite($path);
        if ($full === null) {
            return false;
        }
        $dir = dirname($full);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return false;
        }
        $handle = @fopen($full, 'ab');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $written = fwrite($handle, $content);
            fflush($handle);
            flock($handle, LOCK_UN);
            return $written === strlen($content);
        } finally {
            fclose($handle);
        }
    }

    public function delete(string $path): bool
    {
        $full = $this->resolveExisting($path);
        if ($full === null || !is_file($full)) {
            return false;
        }
        return @unlink($full);
    }

    public function exists(string $path): bool
    {
        return $this->resolveExisting($path) !== null;
    }

    public function size(string $path): ?int
    {
        $full = $this->resolveExisting($path);
        if ($full === null || !is_file($full)) {
            return null;
        }
        clearstat_cache();
        $size = @filesize($full);
        return $size === false ? null : $size;
    }

    public function createDir(string $path): bool
    {
        $full = $this->resolveForWrite($path);
        if ($full === null) {
            return false;
        }
        if (is_dir($full)) {
            return true;
        }
        return @mkdir($full, 0o775, true);
    }

    public function list(string $pattern): array
    {
        $normalized = $this->normalizeRelative($pattern);
        if ($normalized === null) {
            return [];
        }
        $matches = glob($this->root . DIRECTORY_SEPARATOR . $normalized, GLOB_NOSORT);
        if ($matches === false) {
            return [];
        }
        $prefix = $this->root . DIRECTORY_SEPARATOR;
        $result = [];
        foreach ($matches as $match) {
            $real = realpath($match);
            if ($real === false || !$this->insideRoot($real)) {
                continue; // симлинк наружу — не показываем
            }
            $result[] = substr($real, strlen($prefix));
        }
        sort($result);
        return $result;
    }

    /** Разрешает путь существующего объекта (с проверкой симлинков). */
    private function resolveExisting(string $path): ?string
    {
        $normalized = $this->normalizeRelative($path);
        if ($normalized === null) {
            return null;
        }
        $candidate = $this->root . DIRECTORY_SEPARATOR . $normalized;
        $real = realpath($candidate);
        if ($real === false || !$this->insideRoot($real)) {
            return null;
        }
        return $real;
    }

    /**
     * Разрешает путь для создания (файла/папки): нормализованный путь гарантированно
     * внутри песочницы; симлинки существующей части пути проверяются.
     */
    private function resolveForWrite(string $path): ?string
    {
        $normalized = $this->normalizeRelative($path);
        if ($normalized === null) {
            return null;
        }
        $candidate = $this->root . DIRECTORY_SEPARATOR . $normalized;
        $probe = dirname($candidate);
        while ($probe !== $this->root && !is_dir($probe)) {
            $probe = dirname($probe);
        }
        $realParent = realpath($probe);
        if ($realParent === false || !$this->insideRoot($realParent)) {
            return null;
        }
        return $candidate;
    }

    /** Нормализует относительный путь; null — при выходе за корень или недопустимых символах. */
    private function normalizeRelative(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $path = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null; // попытка выйти выше корня
                }
                array_pop($parts);
                continue;
            }
            if (str_contains($part, ':')) {
                return null; // схемы вида C:/ или stream://
            }
            $parts[] = $part;
        }
        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    private function insideRoot(string $realPath): bool
    {
        return $realPath === $this->root || str_starts_with($realPath, $this->root . DIRECTORY_SEPARATOR);
    }
}
