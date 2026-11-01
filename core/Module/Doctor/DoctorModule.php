<?php
declare(strict_types=1);

namespace Core\Module\Doctor;

use Core\Contract\CacheInterface;
use Core\Contract\ConfigInterface;
use Core\Contract\ContainerInterface;
use Core\Contract\DatabaseInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\HealthCheckInterface;
use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\QueueInterface;

/**
 * Модуль Doctor (P3, §7.2). Зависимостей от контрактов нет — работает с
 * ядром через KernelInterface/ContainerInterface (§16.8).
 *
 * boot(): собирает самодиагностику со ВСЕХ поднятых модулей — тех, что
 * реализуют HealthCheckInterface сами (экземпляр-синглтон в контейнере),
 * либо предоставляют отдельный объект-отчётчик. Никакого reflection:
 * список строится из Реестра (§25) и контейнера по публичным контрактам.
 */
final class DoctorModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Doctor';
    }

    public function getVersion(): string
    {
        return Doctor::VERSION;
    }

    public function getDependencies(): array
    {
        // Явных контрактных зависимостей нет: Doctor обязан подняться ПОСЛЕ
        // всех системных модулей — порядок задаётся явно в setSystemModules().
        return [];
    }

    public function getProvides(): array
    {
        return [Doctor::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        assert($container instanceof ContainerInterface);
        $doctor = new Doctor();

        // 1. Модули, отчитывающиеся сами (§7.2: HealthCheckInterface на сервисе).
        //    Источник истины — Реестр поднятых модулей (§25), reflection не используется.
        foreach ($kernel->getRegistry()->all() as $module) {
            foreach ($module->getProvides() as $contract) {
                if (!$container->has($contract)) {
                    continue;
                }
                $service = $container->get($contract);
                if ($service instanceof HealthCheckInterface) {
                    $doctor->register($service);
                }
            }
        }

        // 2. Классические P0/P1-сервисы через публичные контракты (probe-обёртки).
        $pick = static function (string $id) use ($container): ?object {
            return $container->has($id) ? $container->get($id) : null;
        };
        foreach (ContractHealthChecks::for(
            $pick(FileStorageInterface::class),
            $pick(LoggerInterface::class),
            $pick(ConfigInterface::class),
            $pick(CacheInterface::class),
            $pick(DatabaseInterface::class),
            $pick(QueueInterface::class),
        ) as $check) {
            $doctor->register($check);
        }

        $container->instance(Doctor::class, $doctor);
    }
}
