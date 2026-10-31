<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт файлового хранилища (Статья 16.1, P0 — FileStorage).
 * Единственный легальный способ работы ядра и сайтов с ФС (Статья 23).
 */
interface FileStorageInterface
{
    public function load(string $path): ?string;

    public function save(string $path, string $content): bool;

    /** Атомарное добавление в конец файла (LOCK_EX) — для логов и потокового экспорта. */
    public function append(string $path, string $content): bool;

    public function delete(string $path): bool;

    public function exists(string $path): bool;

    /** Размер файла в байтах или null, если файл не существует. */
    public function size(string $path): ?int;

    public function createDir(string $path): bool;

    /**
     * @return string[] Список путей, удовлетворяющих glob-паттерну.
     */
    public function list(string $pattern): array;
}
