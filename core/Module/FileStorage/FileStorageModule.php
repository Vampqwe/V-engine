<?php
declare(strict_types=1);

namespace Core\Module\FileStorage;

use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\SiteContextInterface;

/**
 * Модуль ядра P0: FileStorage. Регистрация реализации в DI (Статья 11).
 */
final class FileStorageModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'filestorage';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    /** Контракт, который предоставляет модуль (для топосортировки Реестра). */
    public function getProvidedContract(): string
    {
        return FileStorageInterface::class;
    }

    public function boot(KernelInterface $kernel): void
    {
        $kernel->getContainer()->set(FileStorageInterface::class, static function () use ($kernel): FileStorage {
            /** @var SiteContextInterface $context */
            $context = $kernel->get(SiteContextInterface::class);
            return FileStorage::fromContext($context);
        });
    }

    public function getDependencies(): array
    {
        return [SiteContextInterface::class];
    }
}
