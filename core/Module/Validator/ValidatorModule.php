<?php
declare(strict_types=1);

namespace Core\Module\Validator;

use Core\Contract\ConfigInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\ValidatorInterface;

/**
 * Модуль Validator (P2, §8.3.1). Регистрация чистого валидатора в DI.
 */
final class ValidatorModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Validator';
    }

    public function getVersion(): string
    {
        return DataValidator::VERSION;
    }

    public function getDependencies(): array
    {
        return [ConfigInterface::class];
    }

    public function getProvides(): array
    {
        return [ValidatorInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $kernel->getContainer()->protect(
            ValidatorInterface::class,
            static fn (): ValidatorInterface => new DataValidator()
        );
    }
}
