<?php
declare(strict_types=1);

namespace Core\Contract;

interface ModuleInterface {
    public function getName(): string;
    public function getVersion(): string;
    public function boot(KernelInterface $kernel): void;
    public function getDependencies(): array;
}