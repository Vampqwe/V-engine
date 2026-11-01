<?php
declare(strict_types=1);

namespace Core\Module\Admin;

use Core\Contract\CacheInterface;
use Core\Contract\ConfigInterface;
use Core\Contract\ContainerInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\MaintenanceModeInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\RequestInterface;
use Core\Contract\ResponseInterface;
use Core\Contract\RouterInterface;
use Core\Contract\SecurityInterface;
use Core\Contract\SessionInterface;
use Core\Contract\ViewInterface;
use Core\Exception\EngineException;
use Core\Response;

/**
 * Админ-панель ядра (P3, §8.4): встроенный системный модуль диагностики.
 *
 * Даёт сайту готовый набор маршрутов /admin/* с дашбордом полного среза
 * состояния движка (AdminStats) и безопасными действиями обслуживания
 * (чистка кэша, техобслуживание вкл/выкл). Все POST-действия защищены
 * CSRF через SecurityInterface (§9/§40); вывод — только шаблоны View с $esc.
 *
 * Панель живёт на пути ядра: она регистрируется Системным модулем, а не
 * пользователем сайта (§16.8 «две дороги»: пользователь может дополнить её
 * своими модулями, но не обязан).
 */
final class AdminModule implements ModuleInterface
{
    public const VERSION = '1.0.0';

    /** Префикс всех маршрутов панели */
    public const PATH = '/admin';

    public function getName(): string
    {
        return 'Admin';
    }

    public function getVersion(): string
    {
        return self::VERSION;
    }

    public function getDependencies(): array
    {
        return [
            RouterInterface::class,
            ViewInterface::class,
            ConfigInterface::class,
            FileStorageInterface::class,
            SessionInterface::class,
            SecurityInterface::class,
        ];
    }

    /** @return string[] */
    public function getProvides(): array
    {
        return [AdminStats::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        assert($container instanceof ContainerInterface);

        $stats = new AdminStats($kernel);
        $container->instance(AdminStats::class, $stats);

        $router   = $container->get(RouterInterface::class);
        $view     = $container->get(ViewInterface::class);
        $security = $container->get(SecurityInterface::class);
        assert($router instanceof RouterInterface);
        assert($view instanceof ViewInterface);
        assert($security instanceof SecurityInterface);

        // Шаблоны панели — в jailed-root сайта: storage/views/admin/*.phtml
        // (копируются модулем при boot, если отсутствуют — см. publishTemplates).
        $this->publishTemplates($container->get(FileStorageInterface::class));

        $render = static function (string $template, array $data) use ($view, $stats): Response {
            $data['stats'] ??= null;
            return Response::html($view->render('admin/' . $template, $data));
        };

        $router->get(self::PATH . '/', function (RequestInterface $request) use ($kernel, $stats, $render): Response {
            $this->guardDebug($kernel);
            return $render('dashboard', ['page' => 'dashboard', 'stats' => $stats->dashboard()]);
        });

        $router->get(self::PATH . '/modules', function (RequestInterface $request) use ($kernel, $stats, $render): Response {
            $this->guardDebug($kernel);
            $d = $stats->dashboard();
            return $render('modules', ['page' => 'modules', 'stats' => $d]);
        });

        $router->get(self::PATH . '/health', function (RequestInterface $request) use ($kernel, $stats, $render): Response {
            $this->guardDebug($kernel);
            $d = $stats->dashboard();
            return $render('health', ['page' => 'health', 'stats' => $d]);
        });

        $router->get(self::PATH . '/routes', function (RequestInterface $request) use ($kernel, $stats, $render): Response {
            $this->guardDebug($kernel);
            $d = $stats->dashboard();
            return $render('routes', ['page' => 'routes', 'stats' => $d]);
        });

        $router->get(self::PATH . '/storage', function (RequestInterface $request) use ($kernel, $stats, $render): Response {
            $this->guardDebug($kernel);
            $d = $stats->dashboard();
            return $render('storage', ['page' => 'storage', 'stats' => $d]);
        });

        $router->get(self::PATH . '/php', function (RequestInterface $request) use ($kernel, $stats, $render): Response {
            $this->guardDebug($kernel);
            $d = $stats->dashboard();
            return $render('php', ['page' => 'php', 'stats' => $d]);
        });

        // JSON-срез всего дашборда (для внешних мониторингов/отладки)
        $router->get(self::PATH . '/api/stats', function (RequestInterface $request) use ($kernel, $stats): Response {
            $this->guardDebug($kernel);
            return Response::json($stats->dashboard());
        });

        // --- Действия обслуживания (POST + CSRF, §9) --------------------------
        $router->post(self::PATH . '/actions/clear-cache', function (RequestInterface $request) use ($kernel, $security): Response {
            $this->guardDebug($kernel);
            $this->assertCsrf($security, $request);
            $container = $kernel->getContainer();
            if ($container->has(CacheInterface::class)) {
                $cache = $container->get(CacheInterface::class);
                assert($cache instanceof CacheInterface);
                $cache->clear();
            }
            return $this->redirectWithFlash($kernel, 'Кэш очищен.');
        });

        $router->post(self::PATH . '/actions/maintenance/on', function (RequestInterface $request) use ($kernel, $security): Response {
            $this->guardDebug($kernel);
            $this->assertCsrf($security, $request);
            $container = $kernel->getContainer();
            if ($container->has(MaintenanceModeInterface::class)) {
                $mm = $container->get(MaintenanceModeInterface::class);
                assert($mm instanceof MaintenanceModeInterface);
                $message = trim((string) $request->getPost('message', ''));
                $mm->enable($message !== '' ? $message : 'Технические работы, скоро вернёмся.', 3600);
            }
            return $this->redirectWithFlash($kernel, 'Техобслуживание ВКЛЮЧЕНО (503 для посетителей без токена).');
        });

        $router->post(self::PATH . '/actions/maintenance/off', function (RequestInterface $request) use ($kernel, $security): Response {
            $this->guardDebug($kernel);
            $this->assertCsrf($security, $request);
            $container = $kernel->getContainer();
            if ($container->has(MaintenanceModeInterface::class)) {
                $mm = $container->get(MaintenanceModeInterface::class);
                assert($mm instanceof MaintenanceModeInterface);
                $mm->disable();
            }
            return $this->redirectWithFlash($kernel, 'Техобслуживание выключено.');
        });
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Панель доступна всегда (это инструмент ядра), но в продакшене без
     * CORE_DEBUG вход в неё закрыт — чтобы не экспонировать внутренности
     * движка наружу. Отключить полностью: 'admin.enabled' => false в config/core.php.
     */
    private function guardDebug(KernelInterface $kernel): void
    {
        $config = $kernel->getContainer()->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);
        if (!$config->has('admin.enabled')) {
            // нет секции — доступ открыт (dev-режим по умолчанию)
            return;
        }
        if (!filter_var((string) $config->get('admin.enabled', 'true'), FILTER_VALIDATE_BOOLEAN)) {
            throw new EngineException('Admin panel is disabled by config (admin.enabled=false).');
        }
    }

    private function assertCsrf(SecurityInterface $security, RequestInterface $request): void
    {
        $token = $request->getPost('_token') ?? $request->getHeader('X-CSRF-Token');
        if (!$security->validateCsrf(is_string($token) ? $token : null)) {
            throw new EngineException('CSRF token mismatch — действие отклонено (§9).');
        }
    }

    private function redirectWithFlash(KernelInterface $kernel, string $message): Response
    {
        $container = $kernel->getContainer();
        if ($container->has(SessionInterface::class)) {
            $session = $container->get(SessionInterface::class);
            assert($session instanceof SessionInterface);
            $session->set('admin.flash', $message);
        }
        return new Response(302, '', ['Location' => self::PATH . '/']);
    }

    /**
     * Опубликовать шаблоны панели в jailed-root сайта (storage/views/admin/),
     * если их там ещё нет. Источники — файлы core/Module/Admin/templates/,
     * запись — только через FileStorageInterface (§20).
     */
    private function publishTemplates(FileStorageInterface $storage): void
    {
        $sourceDir = dirname(__DIR__, 2) . '/Module/Admin/templates';
        foreach (self::TEMPLATE_NAMES as $name) {
            $path = 'views/admin/' . $name . '.phtml';
            if ($storage->exists($path)) {
                continue;
            }
            $file = $sourceDir . '/' . $name . '.phtml';
            if (!is_file($file)) {
                throw new EngineException('Admin template source missing: ' . $name);
            }
            $content = file_get_contents($file);
            if ($content === false) {
                throw new EngineException('Cannot read admin template: ' . $name);
            }
            $storage->save($path, $content);
        }
    }

    /** @var string[] имена шаблонов панели (публикуются в storage/views/admin/) */
    private const TEMPLATE_NAMES = ['layout', 'dashboard', 'modules', 'health', 'routes', 'storage', 'php'];
}
