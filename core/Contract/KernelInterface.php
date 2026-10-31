<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт ядра (Статья 16.8). Точка входа — Core\Kernel реализует этот контракт.
 */
interface KernelInterface
{
    public function addUserModule(ModuleInterface $module): void;

    public function getContainer(): ContainerInterface;

    public function getEventBus(): EventBusInterface;

    /** Единственный легальный способ получить сервис ядра (Статья 11). */
    public function get(string $interface): mixed;
}
