<?php
declare(strict_types=1);

namespace Core\Module\FileStorage;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;

/**
 * Модуль-обёртка FileStorage (P0). Регистрирует сервис в контейнере ядра.
 * Root хранилища берётся из config 'storage.path' (секция storage),
 * по умолчанию — «текущий рабочий каталог» движка для тестовых сборок.
 */
final class FileStorageModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'FileStorage';
    }

    public function getVersion(): string
    {
        return FileStorage::VERSION;
    }

    public function getDependencies(): array
    {
        return [ConfigInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);

        $root = (string) ($config->get('storage.path') ?? getcwd());
        $container->protect(FileStorageInterface::class, static fn(): FileStorageInterface => FileStorage::create($root));
    }
}
