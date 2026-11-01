<?php
declare(strict_types=1);

// ==============================================================================
// queue_handlers.php — ОБРАБОТЧИКИ ОЧЕРЕДЕЙ этого сайта (P2 Queue).
// Вызывается: php bin/console.php queue:work --site=newsite.local
// Формат: возвращает callable(KernelInterface $kernel, QueueInterface $queue).
// Ставить задачи в очередь из кода сайта:
//   $kernel->get(QueueInterface::class)->push('newsletter.send', ['to' => ...]);
// ==============================================================================

use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\QueueInterface;

return static function (KernelInterface $kernel, QueueInterface $queue): void {
    // Пример обработчика — раскомментируйте и переименуйте под свою задачу:
    // $queue->registerHandler('newsletter.send', static function (array $payload) use ($kernel): void {
    //     $kernel->get(LoggerInterface::class)->channel('app')
    //         ->info('queue.newsletter.sent', ['payload' => $payload]);
    // });
};
