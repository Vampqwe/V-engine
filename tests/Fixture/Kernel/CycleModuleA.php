<?php
declare(strict_types=1);

namespace Tests\Fixture\Kernel;

use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;

final class CycleModuleA implements ModuleInterface
{
    public function getName(): string
    {
        return 'CycleA';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return ['Core\\Contract\\CycleBInterface'];
    }

    public function getProvides(): array
    {
        return ['Core\\Contract\\CycleAInterface'];
    }

    public function boot(KernelInterface $kernel): void
    {
    }
}
