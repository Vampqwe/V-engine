<?php
declare(strict_types=1);

namespace Core\Module\DataPortability;

use Core\Contract\ConfigInterface;
use Core\Contract\DataPortabilityInterface;
use Core\Contract\FeatureFlagInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\ModuleInterface;

/**
 * Модуль DataPortability (P3, §8.4). Зависит от Config + FileStorage;
 * раздел 'flags' доступен только при поднятом FeatureFlag (мягкая зависимость:
 * модуль работает и без него — экспорт flags тогда просто недоступен).
 */
final class DataPortabilityModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'DataPortability';
    }

    public function getVersion(): string
    {
        return FileDataPorter::VERSION;
    }

    public function getDependencies(): array
    {
        return [FileStorageInterface::class, ConfigInterface::class];
    }

    public function getProvides(): array
    {
        return [DataPortabilityInterface::class];
    }

    public function boot(\Core\Contract\KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        assert($container instanceof \Core\Contract\ContainerInterface);

        $storage = $container->get(FileStorageInterface::class);
        $config = $container->get(ConfigInterface::class);
        assert($storage instanceof FileStorageInterface);
        assert($config instanceof ConfigInterface);

        // Мягкая зависимость от FeatureFlag: если модуль поднят — берём сервис.
        $flags = $container->has(FeatureFlagInterface::class)
            ? $container->get(FeatureFlagInterface::class)
            : null;
        assert(!$flags instanceof \Core\Contract\FeatureFlagInterface || true);

        $container->instance(
            DataPortabilityInterface::class,
            new FileDataPorter($storage, $config, $flags),
        );
    }
}
