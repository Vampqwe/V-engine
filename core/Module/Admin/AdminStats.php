<?php
declare(strict_types=1);

namespace Core\Module\Admin;

use Core\Contract\CacheInterface;
use Core\Contract\ConfigInterface;
use Core\Contract\ContainerInterface;
use Core\Contract\DatabaseInterface;
use Core\Contract\EventBusInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\MaintenanceModeInterface;
use Core\Contract\QueueInterface;
use Core\Contract\RequestInterface;
use Core\Contract\SecurityInterface;
use Core\Contract\SessionInterface;
use Core\Contract\SiteContextInterface;
use Core\Registry;
use Core\Router;
use Core\Module\Doctor\Doctor;

/**
 * Сборщик статистики ядра для админ-панели (P3, §7.2/§8.4).
 *
 * Собирает срез состояния ТОЛЬКО через публичные контракты и сервисы
 * контейнера (никакого reflection и лазов внутрь модулей — §7.3):
 *  - реестр модулей и порядок загрузки (Registry::inspect());
 *  - самодиагностику Doctor (diagnose());
 *  - окружение PHP (расширения, память, загрузка, opcache);
 *  - хранилище сайта (FileStorage::list() — сессии/кэш/очередь/логи);
 *  - маршрутизацию (Router::all()), событийную шину, БД, техобслуживание.
 */
final class AdminStats
{
    public const VERSION = '1.0.0';

    /** Ключевые расширения движка (pdo/json/mbstring/tokenizer из composer.json) */
    private const REQUIRED_EXTENSIONS = ['pdo', 'json', 'mbstring', 'tokenizer'];

    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    /**
     * Полный дашборд: всё, что знает ядро о себе в текущем запросе.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $container = $this->kernel->getContainer();
        $registry  = $this->kernel->getRegistry();

        return [
            'engine'       => $this->engine($container),
            'site'         => $this->site($container),
            'health'       => $this->health($container),
            'modules'      => $this->modules($registry),
            'routes'       => $this->routes($container),
            'events'       => $this->events($container),
            'storage'      => $this->storage($container),
            'sessions'     => $this->sessions($container),
            'cache'        => $this->cache($container),
            'queue'        => $this->queue($container),
            'database'     => $this->database($container),
            'maintenance'  => $this->maintenance($container),
            'php'          => $this->php(),
            'request'      => $this->request($container),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    // =========================================================================
    // Секции
    // =========================================================================

    /** @return array<string, mixed> */
    private function engine(ContainerInterface $container): array
    {
        $config = $container->has(ConfigInterface::class)
            ? $container->get(ConfigInterface::class) : null;
        return [
            'name'        => 'LEX Engine (V-Engine)',
            'version'     => \Core\Kernel::VERSION,
            'router'      => Router::VERSION,
            'debug'       => $config instanceof ConfigInterface
                && filter_var((string) $config->get('core.debug', 'false'), FILTER_VALIDATE_BOOLEAN),
            'timezone'    => date_default_timezone_get(),
            'php_version' => PHP_VERSION,
            'sections'    => $config instanceof ConfigInterface ? array_keys($config->all()) : [],
        ];
    }

    /** @return array<string, mixed> */
    private function site(ContainerInterface $container): array
    {
        if (!$container->has(SiteContextInterface::class)) {
            return ['domain' => '(unknown)'];
        }
        /** @var SiteContextInterface $ctx */
        $ctx = $container->get(SiteContextInterface::class);
        return [
            'domain'     => basename($ctx->getBaseFolder()),
            'base_dir'   => $ctx->getBaseFolder(),
            'url'        => $ctx->getBaseUrl(),
            'env_file'   => is_file($ctx->getEnvPath()) ? 'присутствует' : 'ОТСУТСТВУЕТ (!)',
            'config_dir' => is_dir($ctx->getConfigPath()) ? 'присутствует' : 'ОТСУТСТВУЕТ (!)',
        ];
    }

    /** @return array<string, mixed> */
    private function health(ContainerInterface $container): array
    {
        if (!$container->has(Doctor::class)) {
            return ['status' => 'n/a', 'summary' => ['ok' => 0, 'warning' => 0, 'fail' => 0], 'checks' => []];
        }
        /** @var Doctor $doctor */
        $doctor = $container->get(Doctor::class);
        return $doctor->diagnose();
    }

    /** @return array<int, array<string, mixed>> */
    private function modules(Registry $registry): array
    {
        $order = [];
        foreach ($registry->resolveBootOrder() as $i => $name) {
            $order[(string) $name] = $i + 1;
        }
        $rows = [];
        foreach ($registry->inspect() as $entry) {
            $name = (string) $entry['name'];
            $deps = (array) ($entry['dependencies'] ?? []);
            $rows[] = [
                'name'         => $name,
                'version'      => (string) $entry['version'],
                'boot_order'   => $order[$name] ?? null,
                'type'         => str_starts_with($name, 'lex/') || !isset($order[$name]) ? 'user' : 'system',
                'provides'     => array_map(
                    static fn (string $c): string => self::shortName($c),
                    \Core\Kernel::providesOfPublic($registry->get($name)),
                ),
                'dependencies' => array_map(static fn (string $c): string => self::shortName($c), $deps),
                'missing'      => array_map(
                    static fn (string $c): string => self::shortName($c),
                    (array) $entry['missing'],
                ),
                'health'       => $entry['health'] ?? null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => ($a['boot_order'] ?? 999) <=> ($b['boot_order'] ?? 999));
        return $rows;
    }

    /** @return array<string, mixed> */
    private function routes(ContainerInterface $container): array
    {
        if (!$container->has(\Core\Contract\RouterInterface::class)) {
            return ['total' => 0, 'list' => []];
        }
        $router = $container->get(\Core\Contract\RouterInterface::class);
        $list = $router instanceof Router ? $router->all() : [];
        usort(
            $list,
            static fn (array $a, array $b): int => [$a['pattern'], $a['method']] <=> [$b['pattern'], $b['method']],
        );
        return ['total' => count($list), 'list' => $list];
    }

    /** @return array<string, mixed> */
    private function events(ContainerInterface $container): array
    {
        if (!$container->has(EventBusInterface::class)) {
            return ['listeners' => [], 'total' => 0];
        }
        /** @var EventBusInterface $bus */
        $bus = $container->get(EventBusInterface::class);
        $known = ['onRequestStart', 'onRouteResolved', 'onBeforeRender', 'onAfterRender', 'onRequestEnd', 'onError'];
        $listeners = [];
        foreach ($known as $event) {
            $listeners[$event] = $bus->hasListeners($event);
        }
        return [
            'listeners' => $listeners,
            'total'     => count(array_filter($listeners)),
        ];
    }

    /** @return array<string, mixed> */
    private function storage(ContainerInterface $container): array
    {
        if (!$container->has(FileStorageInterface::class)) {
            return ['root' => null, 'dirs' => []];
        }
        $fs = $container->get(FileStorageInterface::class);
        assert($fs instanceof FileStorageInterface);
        $root = method_exists($fs, 'getRootDir') ? (string) $fs->getRootDir() : null;

        $scan = static function (string $pattern) use ($fs): array {
            $files = 0;
            $bytes = 0;
            foreach ($fs->list($pattern) as $path) {
                if (!is_string($path) || !is_file($path)) {
                    continue;
                }
                $files++;
                $size = @filesize($path);
                $bytes += $size === false ? 0 : $size;
            }
            return ['files' => $files, 'bytes' => $bytes];
        };

        return [
            'root'          => $root,
            'root_writable' => $root !== null && is_writable($root),
            'disk_free'     => $root !== null && is_dir($root) ? @disk_free_space($root) : false,
            'disk_total'    => $root !== null && is_dir($root) ? @disk_total_space($root) : false,
            'dirs'          => [
                'logs'       => $scan('logs/*'),
                'audit'      => $scan('audit/*'),
                'cache'      => $scan('cache/*'),
                'sessions'   => $scan('sessions/*'),
                'queue'      => $scan('queue/*'),
                'flags'      => $scan('flags/*'),
                'views'      => $scan('views/*'),
                'migrations' => $scan('migrations/*'),
                'uploads'    => $scan('uploads/*'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function sessions(ContainerInterface $container): array
    {
        $row = ['driver' => 'n/a', 'id' => null, 'ttl' => null, 'active_files' => 0];
        if ($container->has(SessionInterface::class)) {
            /** @var SessionInterface $session */
            $session = $container->get(SessionInterface::class);
            $row['id'] = $session->id();
        }
        if ($container->has(ConfigInterface::class)) {
            /** @var ConfigInterface $config */
            $config = $container->get(ConfigInterface::class);
            $row['driver'] = (string) $config->get('session.driver', 'file');
            $row['ttl']    = (int) $config->get('session.ttl', 1800);
        }
        if ($container->has(FileStorageInterface::class)) {
            /** @var FileStorageInterface $fs */
            $fs = $container->get(FileStorageInterface::class);
            $count = 0;
            foreach ($fs->list('sessions/*') as $p) {
                if (is_string($p) && is_file($p)) {
                    $count++;
                }
            }
            $row['active_files'] = $count;
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function cache(ContainerInterface $container): array
    {
        $row = ['driver' => 'file', 'entries' => 0, 'bytes' => 0, 'available' => false];
        if ($container->has(ConfigInterface::class)) {
            /** @var ConfigInterface $config */
            $config = $container->get(ConfigInterface::class);
            $row['driver'] = (string) $config->get('cache.driver', 'file');
        }
        $row['available'] = $container->has(CacheInterface::class);
        if ($container->has(FileStorageInterface::class)) {
            /** @var FileStorageInterface $fs */
            $fs = $container->get(FileStorageInterface::class);
            foreach ($fs->list('cache/*') as $p) {
                if (is_string($p) && is_file($p)) {
                    $row['entries']++;
                    $size = @filesize($p);
                    $row['bytes'] += $size === false ? 0 : $size;
                }
            }
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function queue(ContainerInterface $container): array
    {
        $row = ['available' => false, 'pending' => 0, 'failed' => 0];
        if (!$container->has(QueueInterface::class)) {
            return $row;
        }
        /** @var QueueInterface $queue */
        $queue = $container->get(QueueInterface::class);
        try {
            $row['available'] = true;
            $row['pending'] = $queue->pendingCount();
            $row['failed'] = $queue->failedCount();
        } catch (\Throwable) {
            // деградация: панель не должна падать из-за счётчиков
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function database(ContainerInterface $container): array
    {
        $row = ['configured' => false, 'available' => false, 'driver' => null];
        if ($container->has(ConfigInterface::class)) {
            /** @var ConfigInterface $config */
            $config = $container->get(ConfigInterface::class);
            $row['configured'] = (bool) $config->get('database.enabled', false);
        }
        if (!$container->has(DatabaseInterface::class)) {
            return $row;
        }
        /** @var DatabaseInterface $db */
        $db = $container->get(DatabaseInterface::class);
        $row['available'] = $db->isAvailable();
        if ($row['available'] && $container->has(ConfigInterface::class)) {
            $dsn = (string) $container->get(ConfigInterface::class)->get('database.dsn', '');
            $row['driver'] = str_contains($dsn, ':') ? explode(':', $dsn, 2)[0] : null;
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function maintenance(ContainerInterface $container): array
    {
        if (!$container->has(MaintenanceModeInterface::class)) {
            return ['available' => false, 'active' => false];
        }
        /** @var MaintenanceModeInterface $mm */
        $mm = $container->get(MaintenanceModeInterface::class);
        return ['available' => true, 'active' => $mm->active(), 'state' => $mm->state()];
    }

    /** @return array<string, mixed> */
    private function php(): array
    {
        $load = sys_getloadavg();
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $ini = static function (string $key): string {
            $v = ini_get($key);
            return $v === false || $v === '' ? '—' : (string) $v;
        };
        return [
            'version'        => PHP_VERSION,
            'sapi'           => PHP_SAPI,
            'os'             => PHP_OS_FAMILY,
            'memory_mb'      => round(memory_get_usage(true) / 1048576, 1),
            'peak_mb'        => round(memory_get_peak_usage(true) / 1048576, 1),
            'limit'          => $ini('memory_limit'),
            'max_exec'       => $ini('max_execution_time'),
            'upload_max'     => $ini('upload_max_filesize'),
            'post_max'       => $ini('post_max_size'),
            'display_errors' => $ini('display_errors'),
            'load'           => $load === false ? null : array_map(static fn (float $f): float => round($f, 2), $load),
            'extensions'     => [
                'required' => array_map(
                    static fn (string $e): array => ['name' => $e, 'loaded' => extension_loaded($e)],
                    self::REQUIRED_EXTENSIONS,
                ),
                'useful'   => array_map(
                    static fn (string $e): array => ['name' => $e, 'loaded' => extension_loaded($e)],
                    ['pdo_sqlite', 'pdo_mysql', 'pdo_pgsql', 'redis', 'curl', 'openssl', 'zip', 'intl', 'gd'],
                ),
            ],
            'opcache'        => is_array($opcache) && ($opcache['opcache_enabled'] ?? false)
                ? ['enabled' => true, 'hits' => (int) ($opcache['num_hits'] ?? 0)]
                : ['enabled' => false],
        ];
    }

    /** @return array<string, mixed> */
    private function request(ContainerInterface $container): array
    {
        if (!$container->has(RequestInterface::class)) {
            return ['method' => null, 'path' => null];
        }
        /** @var RequestInterface $req */
        $req = $container->get(RequestInterface::class);
        return [
            'method' => $req->getMethod(),
            'path'   => $req->getPath(),
            'ip'     => $req->getHeader('X-Forwarded-For') ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'csrf'   => $container->has(SecurityInterface::class) ? 'готов' : 'нет',
            'ua'     => $req->getHeader('User-Agent'),
        ];
    }

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
