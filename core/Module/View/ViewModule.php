<?php
declare(strict_types=1);

namespace Core\Module\View;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\SecurityInterface;
use Core\Contract\ViewInterface;

/**
 * Модуль View (P1, §8.2.5). Каталог шаблонов — view.dir в конфиге сайта
 * (относительный внутри jailed-root FileStorage).
 */
final class ViewModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'View';
    }

    public function getVersion(): string
    {
        return TemplateView::VERSION;
    }

    public function getDependencies(): array
    {
        return [FileStorageInterface::class, SecurityInterface::class, ConfigInterface::class];
    }

    public function getProvides(): array
    {
        return [ViewInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);

        $viewDir = rtrim((string) ($config->get('view.dir') ?? 'views'), '/');

        $container->protect(ViewInterface::class, static function () use ($container, $viewDir): ViewInterface {
            $storage = $container->get(FileStorageInterface::class);
            assert($storage instanceof FileStorageInterface);
            $security = $container->get(SecurityInterface::class);
            assert($security instanceof SecurityInterface);
            return new TemplateView($storage, $security, $viewDir);
        });
    }
}
