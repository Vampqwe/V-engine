<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт DI-контейнера (Статья 16.8: KernelInterface::getContainer(): ContainerInterface).
 * Требуется Конституцией, но отсутствует в перечне Статьи 16 — замечание зафиксировано
 * в IMPLEMENTATION.md §3.1 (аналогично ConfigInterface).
 */
interface ContainerInterface
{
    public function get(string $id): mixed;

    public function set(string $id, callable $factory): void;

    /** Единственный легальный способ конструирования объектов ядра (Статья 11.1). */
    public function make(string $class, array $parameters = []): object;

    public function has(string $id): bool;
}
