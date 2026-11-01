<?php
declare(strict_types=1);

namespace Core\Module\Queue;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\QueueInterface;

/**
 * Модуль Queue (P2, §16.2). Зависит от P0-пары Config+FileStorage:
 * очередь живёт внутри jailed-root хранилища сайта (§20).
 * Конфиг секции 'queue': dir, visibility_timeout, enabled.
 */
final class QueueModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Queue';
    }

    public function getVersion(): string
    {
        return FileQueue::VERSION;
    }

    public function getDependencies(): array
    {
        return [ConfigInterface::class, FileStorageInterface::class];
    }

    public function getProvides(): array
    {
        return [QueueInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $storage = $container->get(FileStorageInterface::class);
        $config = $container->get(ConfigInterface::class);
        assert($storage instanceof FileStorageInterface);
        assert($config instanceof ConfigInterface);

        $container->protect(
            QueueInterface::class,
            static fn (): QueueInterface => new FileQueue($storage, $config),
        );
    }

    public function healthCheck(): array
    {
        return ['ok' => true, 'version' => FileQueue::VERSION];
    }
}
