<?php
declare(strict_types=1);

namespace Core\Module\Session;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\RequestInterface;
use Core\Contract\SessionInterface;
use Core\Contract\SiteContextInterface;

/**
 * Модуль Session (P1, §8.2.2).
 *
 * Драйверы (SESSION_DRIVER в .env сайта): file (default) | redis | migrating.
 * Миграция file<->redis — read-through + dual-write (MigratingSessionStore),
 * принятое архитектурное решение: смена драйвера не рвёт активные сессии.
 *
 * Публикуемые контракты: SessionInterface (scoped на RequestInterface::class —
 * своя сессия на запрос).
 */
final class SessionModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Session';
    }

    public function getVersion(): string
    {
        return NativeSession::VERSION;
    }

    public function getDependencies(): array
    {
        return [FileStorageInterface::class, ConfigInterface::class];
    }

    /** Контракты, которые модуль поднимает в контейнере (для графа загрузки). */
    public function getProvides(): array
    {
        return [SessionInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);
        $site = $container->get(SiteContextInterface::class);
        assert($site instanceof SiteContextInterface);

        $driver = strtolower((string) ($config->get('session.driver') ?? 'file'));
        $dir = rtrim((string) ($config->get('session.dir') ?? 'sessions'), '/');
        $cookieName = (string) ($config->get('session.cookie') ?? 'VENGINE_SID');

        $storage = $container->get(FileStorageInterface::class);
        assert($storage instanceof FileStorageInterface);
        // jailed-root хранилища уже ограничен сайтом — sessions-подпапка безопасна.
        $storage->createDir($dir);

        $factory = static function () use ($driver, $dir, $cookieName, $storage, $config): NativeSession {
            $fileStore = new FileSessionStore($storage, $dir . '/sess_');
            $incoming = self::incomingSid($cookieName);

            if ($driver === 'redis') {
                $redis = RedisSessionStore::tryConnect(
                    (string) ($config->get('session.redis_host') ?? '127.0.0.1'),
                    (int) ($config->get('session.redis_port') ?? 6379),
                    (string) ($config->get('session.redis_prefix') ?? 'vengine:sess:'),
                );
                $store = $redis !== null
                    ? new MigratingSessionStore($redis, $fileStore) // redis primary, file legacy
                    : $fileStore;                                    // graceful degradation (§7)
            } elseif ($driver === 'migrating') {
                // file primary, redis legacy — откат с redis на file без разрыва сессий.
                $redis = RedisSessionStore::tryConnect(
                    (string) ($config->get('session.redis_host') ?? '127.0.0.1'),
                    (int) ($config->get('session.redis_port') ?? 6379),
                    (string) ($config->get('session.redis_prefix') ?? 'vengine:sess:'),
                );
                $store = $redis !== null
                    ? new MigratingSessionStore($fileStore, $redis)
                    : $fileStore;
            } else {
                $store = $fileStore;
            }

            $session = new NativeSession($store, $incoming);
            $session->setCookieName($cookieName);
            return $session;
        };

        $container->protect(SessionInterface::class, $factory);
    }

    /** SID из cookie: $_COOKIE читается через php://input? Нет — из суперглобалки,
     *  но только CLI/тесты могут передать её напрямую. */
    private static function incomingSid(string $cookieName): ?string
    {
        $sid = $_COOKIE[$cookieName] ?? null;
        return is_string($sid) ? $sid : null;
    }
}
