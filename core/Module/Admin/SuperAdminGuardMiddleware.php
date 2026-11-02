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
        return $this->loginPage($request);
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
    private function loginPage(RequestInterface $request): Response
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
        $body = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>401 · LEX SuperAdmin</title></head>'
            . '<body style="background:#0d1117;color:#e6edf3;font-family:-apple-system,\'Segoe UI\',sans-serif;'
            . 'display:flex;min-height:100vh;align-items:center;justify-content:center">'
            . '<form method="post" action="' . htmlspecialchars($prefix . '/login', ENT_QUOTES) . '"'
            . ' style="width:340px;max-width:92vw;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:26px">'
            . '<h1 style="font-size:16px;margin:0 0 4px">LEX Engine · вход в панель</h1>'
            . '<p style="color:#848d97;font-size:12px;margin:0 0 16px">Системный доступ · вне сайтов и БД</p>'
            . ($hint !== '' ? '<div style="margin-bottom:14px">' . $hint . '</div>' : '')
            . '<label style="display:block;font-size:12px;color:#848d97;margin-bottom:4px">Логин</label>'
            . '<input type="text" name="login" autocomplete="username" required style="width:100%;padding:8px 10px;margin-bottom:12px;background:#010409;color:#e6edf3;border:1px solid #30363d;border-radius:6px">'
            . '<label style="display:block;font-size:12px;color:#848d97;margin-bottom:4px">Пароль</label>'
            . '<input type="password" name="password" autocomplete="current-password" required style="width:100%;padding:8px 10px;margin-bottom:16px;background:#010409;color:#e6edf3;border:1px solid #30363d;border-radius:6px">'
            . '<button type="submit" style="width:100%;padding:9px;background:#1f6feb;color:#fff;border:0;border-radius:6px;font-weight:600;cursor:pointer">Войти</button>'
            . '</form></body></html>';
        return Response::html($body, 401);
    }
}
