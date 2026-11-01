<?php
declare(strict_types=1);

namespace Tests\Fixture\Kernel;

use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;

final class CycleModuleB implements ModuleInterface
{
    public function getName(): string
    {
        return 'CycleB';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return ['Core\\Contract\\CycleAInterface'];
    }

    public function getProvides(): array
    {
        return ['Core\\Contract\\CycleBInterface'];
    }

    public function boot(KernelInterface $kernel): void
    {
    }
}
