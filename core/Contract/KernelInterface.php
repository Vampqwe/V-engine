<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт ядра (Статья 16.8 Конституции).
 * Пользовательские модули получают доступ к движку ТОЛЬКО через этот интерфейс.
 */
interface KernelInterface
{
    public function addUserModule(ModuleInterface $module): void;

    public function getContainer(): ContainerInterface;

    /**
     * EventBus относится к этапу 3 (ШАГ 3 плана реализации).
     * До его появления возвращает null — модули не должны на него полагаться в P0.
     */
    public function getEventBus(): ?EventBusInterface;

    public function get(string $interface): mixed;
}
