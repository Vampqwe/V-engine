<?php
declare(strict_types=1);

namespace Core\Module\Cookie;

use Core\Contract\ConfigInterface;
use Core\Contract\CookieInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\SiteContextInterface;
use Core\Exception\SecurityException;

/**
 * Модуль Cookie (P1, §8.2.3). Подписание значений — SITE_SECRET; Secure-флаг
 * включается автоматически, когда сайт отдаётся по https (SITE_URL).
 */
final class CookieModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Cookie';
    }

    public function getVersion(): string
    {
        return Cookies::VERSION;
    }

    public function getDependencies(): array
    {
        return [ConfigInterface::class];
    }

    public function getProvides(): array
    {
        return [CookieInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);
        $site = $container->get(SiteContextInterface::class);
        assert($site instanceof SiteContextInterface);

        $secret = (string) ($config->get('app.secret') ?? '');
        if ($secret === '') {
            throw new SecurityException('SITE_SECRET is not configured for cookie signing.');
        }
        $secure = str_starts_with($site->getBaseUrl(), 'https://');

        $container->protect(CookieInterface::class, static fn (): CookieInterface => new Cookies(
            $_COOKIE ?? [],
            $secret,
            '/',
            $secure,
        ));
    }
}
