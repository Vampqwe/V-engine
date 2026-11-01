<?php
declare(strict_types=1);

namespace Tests\Fixture\Kernel;

use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\ModuleInterface;

/**
 * Пользовательский модуль-фикстура: зависит от Logger и регистрирует свой контракт.
 */
final class DummySiteModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'DummySite';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return [LoggerInterface::class];
    }

    /** @return string[] */
    public function getProvides(): array
    {
        return [DummyService::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $logger = $container->get(LoggerInterface::class);
        assert($logger instanceof LoggerInterface);
        $logger->info('dummy.boot');
        $container->set(DummyService::class, static fn() => new DummyService('booted'));
    }
}

final class DummyService
{
    public function __construct(public readonly string $state)
    {
    }
}
