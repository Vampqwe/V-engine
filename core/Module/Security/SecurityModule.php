<?php
declare(strict_types=1);

namespace Core\Module\Security;

use Core\Contract\ConfigInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\SecurityInterface;
use Core\Contract\SessionInterface;
use Core\Exception\SecurityException;

/**
 * Модуль Security (P1, §8.2.1). Зависит от Session (CSRF-токен живёт в сессии).
 * SITE_SECRET обязателен для production-сайта; без него — понятная ошибка.
 */
final class SecurityModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Security';
    }

    public function getVersion(): string
    {
        return Security::VERSION;
    }

    public function getDependencies(): array
    {
        return [SessionInterface::class, ConfigInterface::class];
    }

    public function getProvides(): array
    {
        return [SecurityInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);

        $secret = (string) ($config->get('app.secret') ?? '');
        if ($secret === '') {
            throw new SecurityException(
                'APP_SECRET is missing in sites/<домен>/.env. '
                . 'Generate it: php -r "echo bin2hex(random_bytes(32));"',
            );
        }
        if (strlen($secret) < 16) {
            throw new SecurityException('APP_SECRET must be at least 16 characters.');
        }

        $container->protect(SecurityInterface::class, static function () use ($container, $secret): SecurityInterface {
            $session = $container->get(SessionInterface::class);
            assert($session instanceof SessionInterface);
            return new Security($secret, $session);
        });
    }
}
