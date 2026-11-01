<?php
declare(strict_types=1);

// ==============================================================================
// routes.php — МАРШРУТЫ ВАШЕГО САЙТА (это главный файл, который вы правите).
// Вызывается точкой входа public/index.php ПОСЛЕ boot() ядра.
// Формат: возвращает callable(KernelInterface $kernel, RouterInterface $router).
//
// Роутер (§17): паттерны с параметрами {name} и ограничителями {id:\d+}.
// Хендлер обязан вернуть Response (или строку/массив — ядро обернёт сам).
// НИКОГДА не используйте echo/print/exit внутри хендлеров — вывод делает
// только Kernel::send() (§31). Закон проверяет это детекторами (law/).
// ==============================================================================

use Core\Contract\ConfigInterface;
use Core\Contract\KernelInterface;
use Core\Contract\RequestInterface;
use Core\Contract\ResponseInterface;
use Core\Contract\RouterInterface;
use Core\Contract\ViewInterface;
use Core\Response;

return static function (KernelInterface $kernel, RouterInterface $router): void {

    // --- Главная страница: рендер шаблона storage/views/home.phtml ----------
    $router->get('/', static function (RequestInterface $request) use ($kernel): Response {
        /** @var ViewInterface $view */
        $view = $kernel->get(ViewInterface::class);
        /** @var ConfigInterface $config */
        $config = $kernel->get(ConfigInterface::class);

        return Response::html($view->render('home', [
            'title' => 'Мой сайт на LEX · ' . $config->get('engine.site_url', ''),
        ]));
    });

    // --- Пример параметризованного маршрута: /post/{id:\d+} -----------------
    // Типизированный параметр: $id придёт уже как int (автокаст ядра).
    $router->get('/post/{id:\d+}', static function (RequestInterface $request, int $id): Response {
        return Response::json(['post' => $id]);   // заглушка — замените на своё
    });

    // --- Пример POST + CSRF middleware --------------------------------------
    // Middleware безопасности регистрирует модуль Security; имя сервиса —
    // Contract\MiddlewareInterface::class алиасы в контейнере сайта.
    // $router->post('/contact', [$kernel->get(...)...], $csrfMiddlewareClass);

    // --- 404 для всего остального даёт ядро автоматически (Response::notFound)
};
