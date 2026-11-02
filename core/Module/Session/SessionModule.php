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
        $cookieName = (string) ($config->get('session.name') ?? 'VENGINE_SID');

        $storage = $container->get(FileStorageInterface::class);
        assert($storage instanceof FileStorageInterface);
        // jailed-root хранилища уже ограничен сайтом — sessions-подпапка безопасна.
        $storage->createDir($dir);

        // Пер-запросовый кеш экземпляра: factory вызывается при каждом get(),
        // а сессия обязана быть ОДНОЙ на запрос (иначе CSRF-токен, записанный
        // в один экземпляр, не увидит Set-Cookie-слой, читающий другой).
        $cached = null;

        $factory = static function () use (&$cached, $driver, $dir, $cookieName, $storage, $config, $container): NativeSession {
            if ($cached instanceof NativeSession) {
                return $cached;
            }
            $fileStore = new FileSessionStore($storage, $dir . '/sess_');
            // SID из куки ТЕКУЩЕГО запроса через контракт Request (атрибут
            // 'session.sid' кладёт Kernel::run). $_COOKIE не читаем: php -S /
            // fastcgi-переиспользование воркера оставляет в суперглобалке куку
            // прошлого запроса — сессия «прилипала» к первому клиенту, а новый
            // Set-Cookie терялся и CSRF падал с 403 (§9). Атрибута нет (CLI,
            // тесты, прямое обращение) — честный fallback на $_COOKIE.
            $incoming = null;
            if ($container->has(RequestInterface::class)) {
                $req = $container->get(RequestInterface::class);
                if ($req instanceof \Core\Request) {
                    $sid = $req->getCookie($cookieName);
                    $incoming = is_string($sid) && $sid !== '' ? $sid : null;
                } elseif ($req instanceof RequestInterface) {
                    $sidAttr = $req->getAttribute('session.sid');
                    $incoming = is_string($sidAttr) && $sidAttr !== '' ? $sidAttr : null;
                }
            }
            if ($incoming === null) {
                $sid = $_COOKIE[$cookieName] ?? null;
                $incoming = is_string($sid) ? $sid : null;
            }

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
}
