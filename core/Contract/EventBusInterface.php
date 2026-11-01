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
    /** Зарегистрировать слушателя события (больше priority — раньше выполняется). */
    public function listen(string $event, callable $listener, int $priority = 0): void;

    /** Снять все слушатели события (используется ядром при teardown/тестах). */
    public function removeListeners(string $event): void;

    /** Есть ли хотя бы один слушатель у события? */
    public function hasListeners(string $event): bool;

    /**
     * Dispatch события слушателям. Триггерить события ядра вправе ТОЛЬКО
     * системные компоненты (§18): публичный API-метод назван dispatch(), а не
     * trigger() — пользовательский код обязан проходить через контракты ядра,
     * подмена событий запрещена детекторами Lex.
     *
     * Ошибка одного слушателя не останавливает цепочку: она логируется и
     * попадает в результат.
     *
     * @param array<string, mixed> $payload Передаётся каждому слушателю.
     *
     * @return array{results: array<int|string, mixed>, errors: array<int, string>}
     *         results — возвраты слушателей (ключ: event:priority:index),
     *         errors — сообщения Throwable слушателей.
     */
    public function dispatch(string $event, array $payload = []): array;
}
