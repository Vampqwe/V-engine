<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт шины событий (ERRATA-003, Статья 17).
 * Заглушка контракта: реализация появится на ШАГЕ 3 (EventBus/Registry/Router).
 * Объявлен сейчас, чтобы KernelInterface компилировался и подписывался на контракт,
 * а не на конкретный класс.
 */
interface EventBusInterface
{
    /** Зарегистрировать слушателя события. */
    public function listen(string $event, callable $listener, int $priority = 0): void;

    /**
     *Dispatch события слушателям (триггерить могут только системные компоненты ядра, §17).
     *
     * @return array<string, mixed> Накопленные результаты слушателей.
     */
    public function dispatch(string $event, array $payload = []): array;
}
