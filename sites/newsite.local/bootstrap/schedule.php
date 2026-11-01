<?php
declare(strict_types=1);

// ==============================================================================
// schedule.php — РАСПИСАНИЕ CRON-ЗАДАЧ этого сайта (P2 Scheduler).
// Одна строка crontab на весь сайт (движок сам решает, что пора запускать):
//   * * * * * php /path/to/engine/bin/console.php scheduler:run --site=newsite.local
// Формат: возвращает callable(KernelInterface $kernel, SchedulerInterface $s).
// Cron-синтаксис: min hour day month weekday (* списки диапазоны / шаги).
// ==============================================================================

use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\SchedulerInterface;

return static function (KernelInterface $kernel, SchedulerInterface $scheduler): void {
    // Пример — каждую минуту писать heartbeat (для проверки, что cron жив):
    // $scheduler->every('heartbeat', '* * * * *', static function () use ($kernel): void {
    //     $kernel->get(LoggerInterface::class)->channel('app')->info('scheduler.heartbeat');
    // });

    // Пример — ежедневно в 03:15 ставить job в очередь (handler в queue_handlers.php):
    // $scheduler->every('daily-cleanup', '15 3 * * *', null, 'demo.cleanup');
};
