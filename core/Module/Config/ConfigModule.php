<?php
declare(strict_types=1);

namespace Core\Module\Config;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\SiteContextInterface;

/**
 * Модуль ядра P0: Config. Регистрация через DI (Статья 11).
 */
final class ConfigModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'config';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function boot(KernelInterface $kernel): void
    {
        $kernel->getContainer()->set(ConfigInterface::class, static function () use ($kernel): Config {
            /** @var FileStorageInterface $storage */
            $storage = $kernel->get(FileStorageInterface::class);
            /** @var SiteContextInterface $context */
            $context = $kernel->get(SiteContextInterface::class);
            return new Config($storage, $context);
        });
    }

    public function getDependencies(): array
    {
        return [FileStorageInterface::class, SiteContextInterface::class];
    }
}
