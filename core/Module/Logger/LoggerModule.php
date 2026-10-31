<?php
declare(strict_types=1);

namespace Core\Module\Logger;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\ModuleInterface;

/**
 * Модуль ядра P0: Logger. Регистрирует FileLogger в контейнере (DI, Статья 11).
 */
final class LoggerModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'logger';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function boot(KernelInterface $kernel): void
    {
        $kernel->getContainer()->set(LoggerInterface::class, static function () use ($kernel): LoggerInterface {
            /** @var ConfigInterface $config */
            $config = $kernel->get(ConfigInterface::class);
            /** @var FileStorageInterface $storage */
            $storage = $kernel->get(FileStorageInterface::class);
            return new FileLogger(
                $storage,
                (array) $config->get('logger.channels', ['app', 'error', 'config', 'installer', 'security', 'queue']),
            );
        });
    }

    public function getDependencies(): array
    {
        return [ConfigInterface::class, FileStorageInterface::class];
    }
}
