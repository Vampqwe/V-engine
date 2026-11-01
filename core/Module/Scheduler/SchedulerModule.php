<?php
declare(strict_types=1);

namespace Core\Module\Scheduler;

use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\QueueInterface;
use Core\Contract\SchedulerInterface;

/**
 * Модуль Scheduler (P2). Зависит от FileStorage; мягко использует Queue,
 * если он загружен (задачи могут пушиться в очередь вместо синхронного запуска).
 */
final class SchedulerModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Scheduler';
    }

    public function getVersion(): string
    {
        return FileScheduler::VERSION;
    }

    public function getDependencies(): array
    {
        return [FileStorageInterface::class];
    }

    public function getProvides(): array
    {
        return [SchedulerInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $storage = $container->get(FileStorageInterface::class);
        assert($storage instanceof FileStorageInterface);

        // Queue — опциональная зависимость: берём лениво внутри фабрики,
        // чтобы Scheduler работал и без очереди (только sync-колбэки).
        $container->protect(SchedulerInterface::class, static function () use ($container, $storage): SchedulerInterface {
            $queue = null;
            try {
                $candidate = $container->get(QueueInterface::class);
                if ($candidate instanceof QueueInterface) {
                    $queue = $candidate;
                }
            } catch (\Throwable) {
                // Queue не зарегистрирована — допустимая деградация.
            }
            return new FileScheduler($storage, $queue);
        });
    }

    public function healthCheck(): array
    {
        return ['ok' => true, 'version' => FileScheduler::VERSION];
    }
}
