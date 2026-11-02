<?php
declare(strict_types=1);

namespace Core\Module\Admin;

use Core\Contract\MiddlewareInterface;
use Core\Contract\RequestInterface;
use Core\Contract\SuperAdminAuthenticatorInterface;
use Core\Response;

/**
 * Системный guard-привод панели супер-администратора (§8.4).
 *
 * Закрывает ВСЁ пространство системных путей (/system/superadminpanel/* и
 * legacy /admin/*) за исключением маршрутов входа (login/logout): запросы
 * без валидной HMAC-cookie получают 401 со страницей логина. Работает на
 * ЛЮБОМ домене — панель принадлежит ядру, а не сайту.
 *
 * Middleware регистрируется AdminModule::boot() как system middleware,
 * т.е. выполняется раньше маршрутного pipeline — обмануть его «своим»
 * маршрутом сайта невозможно (§4, конвейер строго упорядочен).
 */
final class SuperAdminGuardMiddleware implements MiddlewareInterface
{
    /**
     * @param SuperAdminAuthenticatorInterface|null $auth null = секрет не настроен
     *        (панель в режиме «настрой меня»: публичные страницы тоже закрыты,
     *        кроме login — там показывается CLI-подсказка).
     * @param string[] $prefixes префиксы защищённого пространства
     */
    public function __construct(
        private readonly ?SuperAdminAuthenticatorInterface $auth,
        private readonly array $prefixes = [AdminModule::SYSTEM_PATH, AdminModule::PATH],
    ) {
    }

    public function handle(RequestInterface $request, callable $next): object
    {
        $path = '/' . ltrim($request->getPath(), '/');
        if (!$this->isSystemPath($path)) {
            return $next($request); // вне пространства панели — не мешаем
        }
        if ($this->isPublicEntry($path)) {
            return $next($request); // login/logout доступны без сессии
        }
        if ($this->auth !== null && $this->auth->validateToken($this->cookie($request))) {
            return $next($request); // сессия супер-админа валидна
        }
        // unknown-host: панель обязана работать на ЛЮБОМ домене — даже когда
        // сайт не определён и маршруты AdminModule не зарегистрированы (§8.4).
        // Тогда guard сам обслуживает весь минимальный цикл панели: страница
        // входа, приём логина (file-key + lockout), выдача HMAC-cookie на
        // движковом CORE_SECRET и дашборд «только для чтения». Секрет — тот же
        // fallback, что использует AdminModule::boot(); при его отсутствии
        // cookie не подписать — страница входа показывает CLI-подсказку (§31).
        $fallback = $this->auth === null ? self::fallbackAuth() : null;
        if ($fallback !== null) {
            $path = '/' . ltrim($request->getPath(), '/');
            if ($fallback->validateToken($this->cookie($request))) {
                if ($this->isPanelRoot($path)) {
                    return $this->dashboardPage($request, $fallback);
                }
                return $next($request); // login/logout — пусть живые маршруты решают
            }
            if ($this->loginEntry($path) && strtoupper($request->getMethod()) === 'POST') {
                $ip = (string) $request->getAttribute('client.ip', '');
                if ($fallback->isLockedOut($ip)) {
                    return $this->loginPage($request, 'locked');
                }
                $login = trim((string) ($request->getPost('login') ?? ''));
                $password = (string) ($request->getPost('password') ?? '');
                if (!$fallback->attempt($login, $password, $ip)) {
                    return $this->loginPage($request, 'bad');
                }
                $token = $fallback->issueToken();
                return Response::text('', 302)
                    ->withHeader('Location', $this->panelHome($request))
                    ->withHeader(
                        'Set-Cookie',
                        SuperAdminAuth::COOKIE_NAME . '=' . $token
                        . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=' . (2 * 3600),
                    );
            }
            if ($this->logoutEntry($path)) {
                return Response::text('', 302)
                    ->withHeader('Location', $this->prefixOf($path) . '/login')
                    ->withHeader(
                        'Set-Cookie',
                        SuperAdminAuth::COOKIE_NAME . '=deleted; Path=/; HttpOnly; SameSite=Lax; Max-Age=0',
                    );
            }
        }
        return $this->loginPage($request);
    }

    /** Корень панели (дашборд) — единственный защищённый экран режима «без сайта». */
    private function isPanelRoot(string $path): bool
    {
        foreach ([AdminModule::SYSTEM_PATH, AdminModule::PATH] as $prefix) {
            if ($path === $prefix || $path === $prefix . '/') {
                return true;
            }
        }
        return false;
    }

    /** Префикс, к которому относится путь (system vs legacy /admin). */
    private function prefixOf(string $path): string
    {
        return str_starts_with($path, AdminModule::PATH) ? AdminModule::PATH : AdminModule::SYSTEM_PATH;
    }

    /**
     * Минимальный дашборд режима «без сайта»: только системная информация
     * (PHP, движок, кол-во сайтов) — без изменений и без зависимостей от
     * Storage/View сайта. Данные краткие и не секреты (§31: наружу — минимум).
     */
    private function dashboardPage(RequestInterface $request, SuperAdminAuth $auth): Response
    {
        $sites = [];
        $dir = ENGINE_BASE_PATH . '/sites';
        foreach (is_dir($dir) ? scandir($dir) : [] as $d) {
            if ($d[0] !== '.' && is_dir($dir . '/' . $d)) {
                $sites[] = $d;
            }
        }
        $base = htmlspecialchars($this->prefixOf('/' . ltrim($request->getPath(), '/')), ENT_QUOTES);
        $rows = [
            'Engine' => Kernel::VERSION,
            'PHP' => PHP_VERSION,
            'Sites' => implode(', ', $sites) ?: '-',
            'Session' => 'HMAC-cookie · file-key (Argon2id) · без БД',
        ];
        $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>LEX SuperAdmin · system</title></head>'
            . '<body style="background:#0d1117;color:#e6edf3;font-family:-apple-system,'Segoe UI',sans-serif;padding:40px">'
            . '<h1 style="font-size:18px">LEX Engine · панель супер-администратора</h1>'
            . '<p style="color:#848d97;font-size:12px">Режим «система» (хост без привязки к сайту): '
            . 'доступны только сведения инсталляции. Полный контур панели — на сайте с config/.</p>'
            . '<table style="border-collapse:collapse;font-size:13px;margin-top:16px">';
        foreach ($rows as $k => $v) {
            $html .= '<tr><td style="padding:6px 14px 6px 0;color:#848d97">' . htmlspecialchars((string) $k, ENT_QUOTES)
                . '</td><td style="padding:6px 0">' . htmlspecialchars((string) $v, ENT_QUOTES) . '</td></tr>';
        }
        $html .= '</table><form method="post" action="' . $base . '/logout" style="margin-top:24px">'
            . '<button type="submit" style="padding:8px 16px;background:#21262d;color:#e6edf3;border:1px solid #30363d;'
            . 'border-radius:6px;cursor:pointer">Выйти</button></form></body></html>';
        return Response::html($html);
    }

    /**
     * Приём логина БЕЗ сайта (unknown-host): POST /system/superadminpanel/login
     * приходит на публичный маршрут guard-исключения, но живой login-обработчик
     * модуля не зарегистрирован (нет sites/<домен>/config/) — тогда проверяем
     * учётку напрямую через file-key (system/admin.key) и выдаём HMAC-cookie на
     * движковом CORE_SECRET. На сайтах с живым модулем этот путь не используется:
     * там работает полноценный login-маршрут с CSRF (§9).
     */
    private function issueAllowed(RequestInterface $request): bool
    {
        return strtoupper($request->getMethod()) === 'POST'
            && $this->loginEntry('/' . ltrim($request->getPath(), '/'));
    }

    /** Совпадает ли путь с точкой приёма логина любого из префиксов? */
    private function loginEntry(string $path): bool
    {
        foreach ([AdminModule::SYSTEM_PATH, AdminModule::PATH] as $prefix) {
            if ($path === $prefix . '/login') {
                return true;
            }
        }
        return false;
    }

    /** Выход из сессии (публичный маршрут-исключение guard). */
    private function logoutEntry(string $path): bool
    {
        foreach ([AdminModule::SYSTEM_PATH, AdminModule::PATH] as $prefix) {
            if ($path === $prefix . '/logout') {
                return true;
            }
        }
        return false;
    }

    /** Куда вести после входа: сохраняем пространство путей (system vs legacy). */
    private function panelHome(RequestInterface $request): string
    {
        $p = '/' . ltrim($request->getPath(), '/');
        $prefix = str_starts_with($p, AdminModule::PATH) ? AdminModule::PATH : AdminModule::SYSTEM_PATH;
        return $prefix . '/';
    }

    /**
     * Guard-аутентификатор для режима «нет сайта» (host не связан с папкой):
     * ключевая file-lockout база + HMAC-cookie на движковом секрете CORE_SECRET.
     * Без секретов возвращает null — страница входа покажет CLI-подсказку (§31).
     */
    private static function fallbackAuth(): ?SuperAdminAuth
    {
        static $cached = [];
        $secret = getenv('CORE_SECRET');
        $secret = is_string($secret) ? trim($secret) : '';
        if (strlen($secret) < 16) {
            return null;
        }
        $dir = SuperAdminAuth::systemDir();
        if (!isset($cached[$dir])) {
            $cached[$dir] = new SuperAdminAuth($dir, $secret);
        }
        return $cached[$dir];
    }

    /** Принадлежит ли путь пространству панели (точный или префиксный)? */
    private function isSystemPath(string $path): bool
    {
        foreach ($this->prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    /** Публичные точки входа: страница и приём логина, выход из сессии. */
    private function isPublicEntry(string $path): bool
    {
        foreach ($this->prefixes as $prefix) {
            $base = rtrim($prefix, '/');
            if ($path === $base . '/login' || $path === $base . '/logout') {
                return true;
            }
        }
        return false;
    }

    private function cookie(RequestInterface $request): ?string
    {
        $header = $request->getHeader('cookie');
        if (!is_string($header) || $header === '') {
            return null;
        }
        foreach (explode(';', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === SuperAdminAuth::COOKIE_NAME) {
                return rawurldecode((string) $value);
            }
        }
        return null;
    }

    /**
     * 401 + встроенная форма входа (без зависимости от View/Storage: guard
     * может вызываться и при недоступности шаблонов сайта). POST на тот же
     * префикс, что и запрос, — панель остаётся «в своём» пространстве путей.
     */
    private function loginPage(RequestInterface $request, string $notice = ''): Response
    {
        $path = '/' . ltrim($request->getPath(), '/');
        $prefix = AdminModule::SYSTEM_PATH;
        foreach ($this->prefixes as $p) {
            if ($path === $p || str_starts_with($path, rtrim($p, '/') . '/')) {
                $prefix = $p;
                break;
            }
        }
        $setupHint = $this->auth === null || !$this->auth->isConfigured();
        $hint = $setupHint
            ? '<p style="color:#d29922;font-size:13px">Ключ супер-админа не настроен. Выполните на сервере:'
            . ' <code style="color:#79c0ff">php bin/console.php admin:passwd --login=admin</code></p>'
            : '';
        // Статусы попытки: 401 (неверные данные) / 429 (lockout) — без подсказки,
        // что именно неверно (§31); «bad»/«locked» приходят только из fallback-привода.
        $status = match ($notice) {
            'locked' => 429,
            default => 401,
        };
        $error = match ($notice) {
            'bad' => '<p style="color:#f85149;font-size:13px;margin:0 0 14px">Неверные учётные данные.</p>',
            'locked' => '<p style="color:#f85149;font-size:13px;margin:0 0 14px">Слишком много попыток. Подождите и повторите.</p>',
            default => '',
        };
        $body = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>401 · LEX SuperAdmin</title></head>'
            . '<body style="background:#0d1117;color:#e6edf3;font-family:-apple-system,\'Segoe UI\',sans-serif;'
            . 'display:flex;min-height:100vh;align-items:center;justify-content:center">'
            . '<form method="post" action="' . htmlspecialchars($prefix . '/login', ENT_QUOTES) . '"'
            . ' style="width:340px;max-width:92vw;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:26px">'
            . '<h1 style="font-size:16px;margin:0 0 4px">LEX Engine · вход в панель</h1>'
            . '<p style="color:#848d97;font-size:12px;margin:0 0 16px">Системный доступ · вне сайтов и БД</p>'
            . ($error !== '' ? $error : '')
            . ($hint !== '' ? '<div style="margin-bottom:14px">' . $hint . '</div>' : '')
            . '<label style="display:block;font-size:12px;color:#848d97;margin-bottom:4px">Логин</label>'
            . '<input type="text" name="login" autocomplete="username" required style="width:100%;padding:8px 10px;margin-bottom:12px;background:#010409;color:#e6edf3;border:1px solid #30363d;border-radius:6px">'
            . '<label style="display:block;font-size:12px;color:#848d97;margin-bottom:4px">Пароль</label>'
            . '<input type="password" name="password" autocomplete="current-password" required style="width:100%;padding:8px 10px;margin-bottom:16px;background:#010409;color:#e6edf3;border:1px solid #30363d;border-radius:6px">'
            . '<button type="submit" style="width:100%;padding:9px;background:#1f6feb;color:#fff;border:0;border-radius:6px;font-weight:600;cursor:pointer">Войти</button>'
            . '</form></body></html>';
        return Response::html($body, $status);
    }
}
