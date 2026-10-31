<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт событийной шины (Статья 16.8: KernelInterface::getEventBus(): EventBusInterface).
 * Требуется Конституцией, но отсутствует в перечне Статьи 16 — замечание в IMPLEMENTATION.md §3.1.
 * Статья 18: пользователь может только слушать (listen), триггерить события ядра запрещено.
 */
interface EventBusInterface
{
    public function listen(string $event, callable $listener): void;

    /** Внутренний API ядра. Пользователю недоступен (Статья 23). */
    public function dispatch(string $event, mixed $payload = null): mixed;

    /** @return callable[] */
    public function getListeners(string $event): array;
}
