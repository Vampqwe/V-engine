<?php
declare(strict_types=1);

// ==============================================================================
// Расписание сайта demo.localhost (LEX Engine, P2 Scheduler).
// Формат: возвращает callable(KernelInterface $kernel, SchedulerInterface $s).
// Вызывается bin/console.php scheduler:run --site=demo.localhost из cron.
// Cron-синтаксис: min hour day month weekday (5 полей, * / списки / диапазоны / шаги).
// ==============================================================================

use Core\Contract\KernelInterface;
use Core\Contract\SchedulerInterface;

return static function (KernelInterface $kernel, SchedulerInterface $scheduler): void {
    // Пример: каждую минуту логируем heartbeat в канал app.
    $scheduler->every('demo-heartbeat', '* * * * *', static function () use ($kernel): void {
        $kernel->get(\Core\Contract\LoggerInterface::class)
            ->channel('app')
            ->info('scheduler.demo.heartbeat');
    });

    // Пример ежедневной задачи в 03:15 — выталкивается в очередь (job),
    // обработчик зарегистрирован в bootstrap/queue_handlers.php.
    $scheduler->every('demo-daily-cleanup', '15 3 * * *', null, 'demo.cleanup');
};
