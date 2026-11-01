<?php
declare(strict_types=1);

namespace Core\Module\FileUpload;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\FileUploadInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;

/**
 * Модуль FileUpload (P2, §16.2). Зависит от P0-пары Config+FileStorage:
 * загрузка возможна только в jailed-root хранилище сайта.
 * Конфиг секции 'upload': max_size, allowed_ext[], allowed_mime[].
 */
final class FileUploadModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'FileUpload';
    }

    public function getVersion(): string
    {
        return SafeFileUploader::VERSION;
    }

    public function getDependencies(): array
    {
        return [ConfigInterface::class, FileStorageInterface::class];
    }

    public function getProvides(): array
    {
        return [FileUploadInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $storage = $container->get(FileStorageInterface::class);
        $config = $container->get(ConfigInterface::class);
        assert($storage instanceof FileStorageInterface);
        assert($config instanceof ConfigInterface);

        $container->protect(
            FileUploadInterface::class,
            static fn (): FileUploadInterface => new SafeFileUploader($storage, $config),
        );
    }

    public function healthCheck(): array
    {
        return ['ok' => true, 'version' => self::class];
    }
}
