<?php
declare(strict_types=1);

namespace Core\Module\AuditLog;

use Core\Contract\AuditLogInterface;
use Core\Contract\DatabaseInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;

/**
 * Модуль AuditLog (P2). Зависит от FileStorage; Database — опционально
 * (через isAvailable() выбирается хранилище БД vs файл-деградация).
 */
final class AuditLogModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'AuditLog';
    }

    public function getVersion(): string
    {
        return FileAuditLog::VERSION;
    }

    public function getDependencies(): array
    {
        return [FileStorageInterface::class];
    }

    public function getProvides(): array
    {
        return [AuditLogInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $storage = $container->get(FileStorageInterface::class);
        assert($storage instanceof FileStorageInterface);

        $database = null;
        try {
            $candidate = $container->get(DatabaseInterface::class);
            if ($candidate instanceof DatabaseInterface) {
                $database = $candidate;
            }
        } catch (\Throwable) {
            // Database не зарегистрирована/не настроена — файловый режим.
        }

        // IP берём из $_SERVER на момент создания экземпляра: фабрика вызывается
        // лениво (при первом get), то есть уже внутри HTTP-цикла, а не на boot().
        $container->protect(AuditLogInterface::class, static function () use ($storage, $database): AuditLogInterface {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? filter_var((string) $_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) : null;
            return new FileAuditLog($storage, $database, $ip === false ? null : $ip);
        });
    }

    public function healthCheck(): array
    {
        return ['ok' => true, 'version' => FileAuditLog::VERSION];
    }
}
