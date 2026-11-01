<?php
declare(strict_types=1);

namespace Core\Module\Logger;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\ModuleInterface;

/**
 * Модуль-обёртка Logger (P0). Зависит от FileStorage (ШАГ 2 порядок §8:
 * FileStorage → Logger → Config подтверждён графом зависимостей контрактов).
 */
final class LoggerModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Logger';
    }

    public function getVersion(): string
    {
        return FileLogger::VERSION;
    }

    public function getDependencies(): array
    {
        return [FileStorageInterface::class, ConfigInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);

        $logDir = (string) ($config->get('logger.dir') ?? 'logs');
        $defaultChannel = (string) ($config->get('logger.channel') ?? 'app');

        $container->protect(LoggerInterface::class, static fn() => new FileLogger(
            $container->get(FileStorageInterface::class),
            $defaultChannel,
            $logDir,
        ));
    }
}
