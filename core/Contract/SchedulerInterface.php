<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт планировщика (P2, §16.2).
 *
 * Scheduler НЕ является демоном: cron вызывает bin/scheduler.php,
 * который один проход сверяет расписание и запускает задачи очереди
 * (или синхронные колбэки) по расписанию. Хранение «last run» — файл
 * в jailed-root хранилище.
 */
interface SchedulerInterface
{
    /**
     * Зарегистрировать периодическую задачу.
     *
     * @param string $name   Уникальное имя.
     * @param string $cron   Упрощённое выражение: "* /5 * * * *" (без пробела после звёздочки —
     *                       min hour day month weekday; поддерживаются числа, списки "1,2",
     *                       диапазоны "1-5", шаги вида "звезда/5").
     * @param callable|null $handler Синхронный колбэк; если null — push($jobName) в Queue.
     * @param string|null $job Имя задачи в очереди (при handler === null).
     */
    public function every(string $name, string $cron, ?callable $handler = null, ?string $job = null): void;

    /**
     * Запустить все задачи, срок которых наступил в указанную минуту.
     *
     * @return string[] Имена запущенных задач.
     */
    public function run(?int $now = null): array;

    /**
     * Проверить, наступает ли cron-выражение в данный timestamp.
     */
    public function due(string $cron, int $timestamp): bool;
}
