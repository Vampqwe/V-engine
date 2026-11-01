<?php
declare(strict_types=1);

namespace Core\Module\Cache;

use Core\Contract\CacheInterface;
use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;

/**
 * Модуль Cache (P1, §8.2.6). Файловый кэш поверх jailed-root FileStorage;
 * префикс каталога — cache.dir в конфиге сайта.
 */
final class CacheModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Cache';
    }

    public function getVersion(): string
    {
        return FileCache::VERSION;
    }

    public function getDependencies(): array
    {
        return [FileStorageInterface::class, ConfigInterface::class];
    }

    public function getProvides(): array
    {
        return [CacheInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);

        $dir = rtrim((string) ($config->get('cache.dir') ?? 'cache'), '/');
        $storage = $container->get(FileStorageInterface::class);
        assert($storage instanceof FileStorageInterface);
        $storage->createDir($dir);

        $container->protect(CacheInterface::class, static fn (): CacheInterface => new FileCache($storage, $dir));
    }
}
