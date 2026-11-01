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

        $render = static function (string $template, array $data) use ($view, $stats, $security, $container): Response {
            $data['stats'] ??= null;
            // CSRF-токен для POST-форм панели (§9) — всегда свежий на каждый рендер.
            $data['csrf'] = $security->csrfToken();
            // Flash-сообщение после POST-действия — показать один раз.
            if ($container->has(SessionInterface::class)) {
                $session = $container->get(SessionInterface::class);
                assert($session instanceof SessionInterface);
                $flash = $session->get('admin.flash');
                if (is_string($flash) && $flash !== '') {
                    $data['flash'] = $flash;
                    $session->remove('admin.flash');
                }
            }
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
            $denied = $this->assertCsrf($security, $request);
            if ($denied !== null) {
                return $denied;
            }
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
            $denied = $this->assertCsrf($security, $request);
            if ($denied !== null) {
                return $denied;
            }
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
            $denied = $this->assertCsrf($security, $request);
            if ($denied !== null) {
                return $denied;
            }
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

    private function assertCsrf(SecurityInterface $security, RequestInterface $request): Response|null
    {
        $token = $request->getPost('_token') ?? $request->getHeader('X-CSRF-Token');
        if (!$security->validateCsrf(is_string($token) ? $token : null)) {
            // 403 вместо «сырого» исключения: CSRF-ошибка — штатный отказ,
            // а не сбой движка (500 reserved for crashes, §31).
            return Response::html(
                '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>403 · LEX</title></head>'
                . '<body style="background:#0d1117;color:#e6edf3;font-family:sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center">'
                . '<div style="text-align:center"><h1 style="color:#f85149">403</h1>'
                . '<p>CSRF token mismatch — действие отклонено (§9).</p>'
                . '<a href="/admin/" style="color:#1f6feb">← В админ-панель</a></div></body></html>',
                403,
            );
        }
        return null;
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
     *
     * Версия шаблонов хранится рядом (views/admin/.version): при обновлении
     * движка опубликованные копии автоматически перезаписываются эталонными.
     */
    private function publishTemplates(FileStorageInterface $storage): void
    {
        $sourceDir = __DIR__ . '/templates';
        $versionPath = 'views/admin/.version';
        $current = self::TEMPLATE_VERSION;
        if (!$storage->exists($versionPath) || trim((string) $storage->load($versionPath)) !== $current) {
            foreach (self::TEMPLATE_NAMES as $name) {
                $ext = $name === 'helpers' ? 'php' : 'phtml';
                $path = 'views/admin/' . $name . '.' . $ext;
                $file = $sourceDir . '/' . $name . '.' . $ext;
                if (!is_file($file)) {
                    throw new EngineException('Admin template source missing: ' . $name);
                }
                $content = file_get_contents($file);
                if ($content === false) {
                    throw new EngineException('Cannot read admin template: ' . $name);
                }
                $storage->save($path, $content);
            }
            $storage->save($versionPath, $current);
            return;
        }
        foreach (self::TEMPLATE_NAMES as $name) {
            $ext = $name === 'helpers' ? 'php' : 'phtml';
            $path = 'views/admin/' . $name . '.' . $ext;
            if ($storage->exists($path)) {
                continue;
            }
            $file = $sourceDir . '/' . $name . '.' . $ext;
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

    /** Эталонная версия набора шаблонов панели (смена => переустановка копий) */
    private const TEMPLATE_VERSION = '1.0.0';

    /** @var string[] имена шаблонов панели (публикуются в storage/views/admin/) */
    private const TEMPLATE_NAMES = ['helpers', 'layout', 'dashboard', 'modules', 'health', 'routes', 'storage', 'php'];
}
