<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт файлового хранилища (Статья 16.1 Конституции, P0).
 * Единственный легальный способ работы с ФС внутри движка (Статья: запрет
 * прямого файлового ввода-вывода вне FileStorageInterface).
 * Все пути — относительные, резолвятся внутри root-директории хранилища.
 */
interface FileStorageInterface
{
    public function load(string $path): ?string;

    public function save(string $path, string $content): bool;

    public function delete(string $path): bool;

    public function exists(string $path): bool;

    public function createDir(string $path): bool;

    /**
     * Список файлов по glob-паттерну (относительно root).
     *
     * @return string[] Абсолютные пути найденных файлов.
     */
    public function list(string $pattern): array;
}
