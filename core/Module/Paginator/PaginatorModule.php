<?php
declare(strict_types=1);

namespace Core\Module\Paginator;

use Core\Contract\ConfigInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\PaginatorInterface;

/**
 * Модуль Paginator (P2, §8.3.2).
 * Конфиг сайта: paginator.max_per_page (default 200).
 * Сам по себе контейнер регистрирует фабрика-хелпер make() — источник данных
 * передаёт вызывающий код (массив или callable offset/limit/criteria).
 */
final class PaginatorModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Paginator';
    }

    public function getVersion(): string
    {
        return Paginator::VERSION;
    }

    public function getDependencies(): array
    {
        return [ConfigInterface::class];
    }

    public function getProvides(): array
    {
        return [PaginatorInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);

        $max = (int) ($config->get('paginator.max_per_page') ?? 200);
        $max = $max >= 1 ? $max : 200;

        // Фабрика: $container->get(PaginatorInterface::class) вернёт дефолтный no-op нельзя —
        // вместо этого публикуем статический конструктор через замыкание-сервис.
        $container->protect(PaginatorInterface::class, static fn (): PaginatorInterface => new Paginator([]));
        $container->set('paginator.factory', static fn (array $source) => new Paginator($source, $max));
    }
}
