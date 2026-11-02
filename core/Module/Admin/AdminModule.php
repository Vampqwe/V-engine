<?php
declare(strict_types=1);

namespace Core\Module\Admin;

use Core\Contract\CacheInterface;
use Core\Contract\ConfigInterface;
use Core\Contract\ContainerInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\MaintenanceModeInterface;
use Core\Contract\MiddlewareInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\RequestInterface;
use Core\Contract\ResponseInterface;
use Core\Contract\RouterInterface;
use Core\Contract\SecurityInterface;
use Core\Contract\SessionInterface;
use Core\Contract\SuperAdminAuthenticatorInterface;
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

    /** Системный путь панели: работает на ЛЮБОМ домене, не принадлежит сайту. */
    public const SYSTEM_PATH = '/system/superadminpanel';

    /** Оба префикса-алиаса (системный — основной; /admin — legacy). */
    private const PREFIXES = [self::SYSTEM_PATH, self::PATH];

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
        return [AdminStats::class, SuperAdminAuthenticatorInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        assert($container instanceof ContainerInterface);

        // Админ-панель доступна ВСЕГДА и на любом хосте — включая прямой доступ
        // к public/index.php (IP / localhost без виртуального хоста), когда
        // домен определяется как «unknown» и папка sites/<домен> отсутствует.
        // Раньше при неизвестном сайте boot() молча выходил, и панель не
        // регистрировалась → 404 на /admin даже при верном DocumentRoot.
        $stats = new AdminStats($kernel);
        $container->instance(AdminStats::class, $stats);

        // --- Аутентификация супер-админа (вне БД, §8.4) -----------------------
        // Ключ system/admin.key создаётся только CLI `admin:passwd`; сессия —
        // HMAC-cookie; перебор — файловый lockout по IP. Секрет: APP_SECRET
        // сайта, fallback CORE_SECRET движка (для unknown-host / dev).
        $config = $container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);
        $secret = trim((string) ($config->get('app.secret') ?? ''));
        if ($secret === '') {
            $envSecret = getenv('CORE_SECRET');
            $secret = is_string($envSecret) ? trim($envSecret) : '';
        }
        if (strlen($secret) >= 16) {
            $auth = new SuperAdminAuth(SuperAdminAuth::systemDir(), $secret);
            $container->instance(SuperAdminAuthenticatorInterface::class, $auth);
        } else {
            // Секрета нет — панель в режиме «настрой меня»: вход закрыт,
            // страница логина подсказывает CLI-команду. Не бросаем исключение,
            // чтобы не ронять весь сайт из-за отсутствия секрета (§31).
            $auth = null;
        }

        $router   = $container->get(RouterInterface::class);
        $view     = $container->get(ViewInterface::class);
        $security = $container->get(SecurityInterface::class);
        assert($router instanceof RouterInterface);
        assert($view instanceof ViewInterface);
        assert($security instanceof SecurityInterface);

        // Шаблоны панели — в jailed-root сайта: storage/views/admin/*.phtml
        // (копируются модулем при boot, если отсутствуют — см. publishTemplates).
        $this->publishTemplates($container->get(FileStorageInterface::class));

        // Системный middleware: закрывает ВСЁ пространство /system/* и legacy
        // /admin/* за исключением маршрутов входа. Регистрируется раньше
        // маршрутов — панель физически недоступна без сессии супер-админа.
        $kernel->addSystemMiddleware(new SuperAdminGuardMiddleware($auth, self::PREFIXES));

        $render = static function (string $template, array $data) use ($view, $stats, $security, $container): Response {
            $data['stats'] ??= null;
            // CSRF-токен для POST-форм панели (§9) — всегда свежий на каждый рендер.
            $data['csrf'] = $security->csrfToken();
            // Активный префикс текущего запроса: системный путь приоритетнее
            // legacy /admin — ссылки внутри панели держатся «в своём» пространстве.
            $data['base'] ??= self::SYSTEM_PATH;
            if ($container->has(RequestInterface::class)) {
                $req = $container->get(RequestInterface::class);
                assert($req instanceof RequestInterface);
                $p = '/' . ltrim($req->getPath(), '/');
                $data['base'] = str_starts_with($p, self::PATH . '/') || $p === self::PATH
                    ? self::PATH
                    : self::SYSTEM_PATH;
            }
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

        // --- Публичные маршруты входа (исключения guard-middleware) -----------
        $loginRender = static function (array $data) use ($view, $security): Response {
            $data['csrf'] ??= $security->csrfToken();
            $data['action'] ??= self::SYSTEM_PATH . '/login';
            return Response::html($view->render('admin/login', $data));
        };

        foreach (self::PREFIXES as $prefix) {
            $router->get($prefix . '/login', function (RequestInterface $request) use ($auth, $loginRender): Response {
                if ($auth !== null && $auth->isConfigured()) {
                    return $loginRender(['mode' => 'form']);
                }
                return $loginRender(['mode' => 'setup']);
            });

            $router->post($prefix . '/login', function (RequestInterface $request) use ($auth, $security, $loginRender): Response {
                if ($auth === null || !$auth->isConfigured()) {
                    return $loginRender(['mode' => 'setup', 'csrf' => $security->csrfToken()]);
                }
                $denied = $this->assertCsrf($security, $request);
                if ($denied !== null) {
                    return $denied;
                }
                $ip = $this->clientIp($request);
                if ($auth->isLockedOut($ip)) {
                    return $loginRender([
                        'mode' => 'locked',
                        'retryIn' => (string) $auth->lockoutRemaining($ip),
                        'csrf' => $security->csrfToken(),
                    ]);
                }
                $login = trim((string) $request->getPost('login', ''));
                $password = (string) $request->getPost('password', '');
                if (!$auth->attempt($login, $password, $ip)) {
                    // 401 + тот же макет формы: без подсказки, что именно не так (§31)
                    return $loginRender([
                        'mode' => 'form',
                        'error' => 'Неверные учётные данные.',
                        'csrf' => $security->rotateCsrf(),
                    ])->withStatus(401);
                }
                $token = $auth->issueToken();
                $redirect = $this->panelHome($request);
                return Response::text('', 302)
                    ->withHeader('Location', $redirect)
                    ->withHeader(
                        'Set-Cookie',
                        SuperAdminAuth::COOKIE_NAME . '=' . $token
                        . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=' . (2 * 3600),
                    );
            });

            $router->post($prefix . '/logout', function (RequestInterface $request) use ($security): Response {
                $denied = $this->assertCsrf($security, $request);
                if ($denied !== null) {
                    return $denied;
                }
                return Response::text('', 302)
                    ->withHeader('Location', $prefix . '/login')
                    ->withHeader(
                        'Set-Cookie',
                        SuperAdminAuth::COOKIE_NAME . '=deleted; Path=/; HttpOnly; SameSite=Lax; Max-Age=0',
                    );
            });
        }

        // --- Защищённые маршруты панели (за SuperAdminGuardMiddleware) --------
        foreach (self::PREFIXES as $prefix) {
            $router->get($prefix . '/', function (RequestInterface $request) use ($kernel, $stats, $render, $prefix): Response {
                $this->guardDebug($kernel);
                return $render('dashboard', ['page' => 'dashboard', 'stats' => $stats->dashboard(), 'base' => $prefix]);
            });

            $router->get($prefix . '/modules', function (RequestInterface $request) use ($kernel, $stats, $render, $prefix): Response {
                $this->guardDebug($kernel);
                $d = $stats->dashboard();
                return $render('modules', ['page' => 'modules', 'stats' => $d, 'base' => $prefix]);
            });

            $router->get($prefix . '/health', function (RequestInterface $request) use ($kernel, $stats, $render, $prefix): Response {
                $this->guardDebug($kernel);
                $d = $stats->dashboard();
                return $render('health', ['page' => 'health', 'stats' => $d, 'base' => $prefix]);
            });

            $router->get($prefix . '/routes', function (RequestInterface $request) use ($kernel, $stats, $render, $prefix): Response {
                $this->guardDebug($kernel);
                $d = $stats->dashboard();
                return $render('routes', ['page' => 'routes', 'stats' => $d, 'base' => $prefix]);
            });

            $router->get($prefix . '/storage', function (RequestInterface $request) use ($kernel, $stats, $render, $prefix): Response {
                $this->guardDebug($kernel);
                $d = $stats->dashboard();
                return $render('storage', ['page' => 'storage', 'stats' => $d, 'base' => $prefix]);
            });

            $router->get($prefix . '/php', function (RequestInterface $request) use ($kernel, $stats, $render, $prefix): Response {
                $this->guardDebug($kernel);
                $d = $stats->dashboard();
                return $render('php', ['page' => 'php', 'stats' => $d, 'base' => $prefix]);
            });

            // JSON-срез всего дашборда (для внешних мониторингов/отладки)
            $router->get($prefix . '/api/stats', function (RequestInterface $request) use ($kernel, $stats): Response {
                $this->guardDebug($kernel);
                return Response::json($stats->dashboard());
            });

            // --- Действия обслуживания (POST + CSRF, §9) ----------------------
            $router->post($prefix . '/actions/clear-cache', function (RequestInterface $request) use ($kernel, $security): Response {
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

            $router->post($prefix . '/actions/maintenance/on', function (RequestInterface $request) use ($kernel, $security): Response {
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

            $router->post($prefix . '/actions/maintenance/off', function (RequestInterface $request) use ($kernel, $security): Response {
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
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * IP клиента из контракта Request (без чтения суперглобалов вне ядра):
     * REMOTE_ADDR кладётся в атрибут 'client.ip' на этапе построения запроса.
     */
    private function clientIp(RequestInterface $request): string
    {
        $ip = $request->getAttribute('client.ip');
        return is_string($ip) ? $ip : '';
    }

    /** Куда вести после входа: сохраняем пространство путей (system vs legacy). */
    private function panelHome(RequestInterface $request): string
    {
        $p = '/' . ltrim($request->getPath(), '/');
        $prefix = str_starts_with($p, self::PATH) ? self::PATH : self::SYSTEM_PATH;
        return $prefix . '/';
    }

    /**
     * Панель доступна всегда (это инструмент ядра), но её можно полностью
     * закрыть конфигом: 'admin' => ['enabled' => false] в config/core.php.
     * Аутентификация супер-админа (SuperAdminGuardMiddleware) при этом —
     * единственный ключ доступа; guard не имеет «мастер-обхода».
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
        // Сравнение с ЭТАЛОНОМ (а не только с меткой .version): если источник шаблона
        // обновился без bump версии (или опубликованная копия была правлена вручную),
        // копия всё равно переустанавливается эталонной — панель ядра никогда не
        // должна рендерить устаревший/сломанный шаблон (§31, single source of truth).
        $stale = !$storage->exists($versionPath) || trim((string) $storage->load($versionPath)) !== $current;
        if (!$stale) {
            foreach (self::TEMPLATE_NAMES as $name) {
                $ext = $name === 'helpers' ? 'php' : 'phtml';
                $srcFile = $sourceDir . '/' . $name . '.' . $ext;
                if (!is_file($srcFile)) {
                    $stale = true;
                    break;
                }
                $published = $storage->load('views/admin/' . $name . '.' . $ext);
                if (!is_string($published) || $published !== (string) file_get_contents($srcFile)) {
                    $stale = true;
                    break;
                }
            }
        }
        if ($stale) {
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

    /** Эталонная версия набора шаблонов панели (смена => переустановка копий).
     *  1.0.1: в набор добавлен login.phtml (страница входа панели).
     *  1.0.2: helpers.php — все функции обёрнуты в function_exists() (идемпотентный
     *         include: layout + страница тянут хелперы дважды, без фатального redeclare). */
    private const TEMPLATE_VERSION = '1.0.2';

    /** @var string[] имена шаблонов панели (публикуются в storage/views/admin/) */
    private const TEMPLATE_NAMES = ['helpers', 'layout', 'login', 'dashboard', 'modules', 'health', 'routes', 'storage', 'php'];
}
