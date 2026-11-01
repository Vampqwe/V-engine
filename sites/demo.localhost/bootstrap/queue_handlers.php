<?php
declare(strict_types=1);

// ==============================================================================
// Обработчики очередей сайта demo.localhost (LEX Engine, P2 Queue).
// Формат: возвращает callable(KernelInterface $kernel, QueueInterface $queue).
// Вызывается bin/console.php queue:work --site=demo.localhost.
// ==============================================================================

use Core\Contract\KernelInterface;
use Core\Contract\QueueInterface;

return static function (KernelInterface $kernel, QueueInterface $queue): void {
    // Job, который выталкивает Scheduler (demo-daily-cleanup).
    $queue->registerHandler('demo.cleanup', static function (array $payload) use ($kernel): void {
        $kernel->get(\Core\Contract\LoggerInterface::class)
            ->channel('app')
            ->info('queue.demo.cleanup.done', ['payload' => $payload]);
    });
};
