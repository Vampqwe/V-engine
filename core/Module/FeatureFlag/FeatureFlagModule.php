<?php
declare(strict_types=1);

namespace Core\Module\FeatureFlag;

use Core\Contract\ConfigInterface;
use Core\Contract\FeatureFlagInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;

/**
 * Модуль FeatureFlag (P3, §8.4). Зависит от Config + FileStorage.
 * HealthCheck (§7.2) отдаёт сам экземпляр FileFeatureFlags — Doctor
 * агрегирует его через контракт, не подглядывая внутрь модуля.
 */
final class FeatureFlagModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'FeatureFlag';
    }

    public function getVersion(): string
    {
        return FileFeatureFlags::VERSION;
    }

    public function getDependencies(): array
    {
        return [ConfigInterface::class, FileStorageInterface::class];
    }

    public function getProvides(): array
    {
        return [FeatureFlagInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        $storage = $container->get(FileStorageInterface::class);
        assert($config instanceof ConfigInterface);
        assert($storage instanceof FileStorageInterface);

        $container->instance(FeatureFlagInterface::class, new FileFeatureFlags($storage, $config));
    }
}
