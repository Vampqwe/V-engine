<?php
declare(strict_types=1);

namespace Core\Module\Config;

use Core\Contract\ConfigInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\SiteContextInterface;

/**
 * Модуль-обёртка Config (P0). Параметры берутся из SiteContext
 * (многосайтовость: SITE_URL -> SITE_DIR, единая точка входа).
 */
final class ConfigModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Config';
    }

    public function getVersion(): string
    {
        return Config::VERSION;
    }

    public function getDependencies(): array
    {
        return [SiteContextInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $site = $container->get(SiteContextInterface::class);
        assert($site instanceof SiteContextInterface);

        $container->protect(ConfigInterface::class, static function () use ($site): ConfigInterface {
            $config = new Config($site->getConfigPath(), $site->getEnvPath());
            // Системные пути движка доступны как секция 'engine'
            $config->replace('engine', [
                'base_path' => defined('ENGINE_BASE_PATH') ? ENGINE_BASE_PATH : '',
                'site_dir'  => $site->getBaseFolder(),
                'site_url'  => $site->getBaseUrl(),
            ]);
            return $config->load();
        });
    }
}
