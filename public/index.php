<?php
declare(strict_types=1);

// ==============================================================================
// 1. ЕДИНАЯ ТОЧКА ОТЧЕТА (Статья 31 Конституции)
// ==============================================================================
define('ENGINE_BASE_PATH', dirname(__DIR__));

// ==============================================================================
// 2. АВАРИЙНЫЙ ЛЮК (500 Hardcode) - Статья 31 Конституции
// Срабатывает, если ядро упало ДО того, как успело инициализироваться
// ==============================================================================
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        // Читаем статический, безопасный HTML-файл вне досягаемости веб-сервера
        $fallback = ENGINE_BASE_PATH . '/core/Fallback/500.html';
        if (file_exists($fallback)) {
            readfile($fallback);
        } else {
            echo '<h1>500 Internal Server Error</h1>';
        }
        exit(1);
    }
});

// ==============================================================================
// 3. ЗАГРУЗЧИК (Composer autoload — единственный разрешённый require)
// ==============================================================================
$autoload = ENGINE_BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Engine is not installed: run "composer install".';
    exit(1);
}
require_once $autoload;

use Core\Contract\ConfigInterface;
use Core\Contract\RequestInterface;
use Core\Contract\ViewInterface;
use Core\Kernel;
use Core\Module\Config\Config;
use Core\Request;
use Core\Response;

// ==============================================================================
// 4. ОКРУЖЕНИЕ ДВИЖКА (корневой .env — значения по умолчанию для CLI/демо).
//    Каждый сайт определяет СВОЙ .env: sites/<домен>/.env — его читает модуль
//    Config через SiteContext (многосайтовость: SITE_URL жёстко => SITE_DIR).
// ==============================================================================
$engineEnv = [];
$rootEnvPath = ENGINE_BASE_PATH . '/.env';
if (is_file($rootEnvPath)) {
    try {
        $engineEnv = Config::parseEnvFile($rootEnvPath);
    } catch (\Throwable) {
        $engineEnv = []; // повреждённый корневой .env не должен ронять люк
    }
}
// Корневые значения — только ДЕФОЛТЫ режима движка. Привязка сайта к папке
// (ИМЯ ПАПКИ = ДОМЕН) идёт по HTTP_HOST; SITE_URL из корневого .env НЕ должен
// перекрывать виртуальный хост (иначе многосайтовость ломается).
unset($engineEnv['SITE_URL']);
foreach ($engineEnv as $key => $value) {
    if (is_string($key) && getenv($key) === false) {
        putenv($key . '=' . (string) $value);
    }
}

// ==============================================================================
// 5. ИНИЦИАЛИЗАЦИЯ ЯДРА: boot -> маршруты -> run -> send (§16.9)
//    Вывод делает ТОЛЬКО Kernel::send() (§31).
// ==============================================================================
try {
    $kernel = new Kernel(ENGINE_BASE_PATH, null, $_SERVER, $engineEnv);

    // 5.1. Контекст сайта известен ДО boot(): загружаем bootstrap сайта —
    //      пользовательские модули (до boot) и маршруты (после boot, §16.9).
    $siteCtx = \Core\SiteContext::detect(ENGINE_BASE_PATH, $_SERVER, $engineEnv);
    $siteBootstrap = static function (string $name) use ($siteCtx): ?\Closure {
        $file = $siteCtx->getBaseFolder() . '/bootstrap/' . $name;
        if (!is_file($file)) {
            return null;
        }
        $callable = require $file;
        return is_callable($callable) ? \Closure::fromCallable($callable) : null;
    };

    if (($withModules = $siteBootstrap('modules.php')) !== null) {
        $withModules($kernel);   // до boot(): addUserModule(...)
    }

    $kernel->boot();

    /** @var ConfigInterface $config */
    $config = $kernel->get(ConfigInterface::class);
    /** @var \Core\Router $router */
    $router = $kernel->get(\Core\Contract\RouterInterface::class);

    // 5.2. Системные маршруты ядра (/health): регистрируются ВСЕГДА — и до
    //      bootstrap-файла сайта, чтобы пользовательский routes.php мог их
    //      переопределить своим обработчиком. Админ-панель /admin/* поднята
    //      системным модулем AdminModule ещё на boot() — она доступна на
    //      любом хосте независимо от наличия routes.php (§16.8 «две дороги»).
    register_core_routes($kernel, $router, $config);

    // 5.3. Маршруты сайта: sites/<домен>/bootstrap/routes.php (необязателен).
    $withRoutes = $siteBootstrap('routes.php');
    if ($withRoutes !== null) {
        $withRoutes($kernel, $router);
    } else {
        // Демо-главная — только для сайтов без собственного routes.php
        // (иначе не перекроет пользовательский «GET /»: Router отдаёт первое
        // точное совпадение, а дубликат регистрировать нельзя).
        register_demo_routes($kernel, $router, $config);
    }

    $request = Request::fromServer($_SERVER, $_GET, $_POST, $_FILES);
    $response = $kernel->run($request);
} catch (\Throwable $e) {
    // Ядро загрузилось, но упало до/во время boot(): сырое исключение наружу
    // не выпускаем (§31) — только люк-500 с трассировкой в режиме debug.
    $debug = filter_var(getenv('CORE_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $debug ? 'BOOT ERROR: ' . $e . "\n" : 'Internal Server Error';
    exit(1);
}

$kernel->send($response);

// ==============================================================================
// 6. ДЕМО-МАРШРУТЫ (в реальном проекте маршруты регистрируют модули сайта;
//    здесь — минимальный пример полного HTTP-цикла через контракты ядра)
// ==============================================================================

/**
 * Системные маршруты ядра: доступны на ЛЮБОМ хосте и при ЛЮБОМ сайте,
 * даже если sites/<домен>/bootstrap/routes.php отсутствует или не поднялся.
 * Регистрация идёт ДО bootstrap сайта — сайт может переопределить их своим
 * обработчиком (первое точное совпадение выигрывает в Router).
 */
function register_core_routes(Kernel $kernel, \Core\Router $router, ConfigInterface $config): void
{
    $router->get('/health', static function (RequestInterface $request) use ($kernel): Response {
        $modules = [];
        foreach ($kernel->getRegistry()->all() as $module) {
            $modules[] = ['name' => $module->getName(), 'version' => $module->getVersion()];
        }
        return Response::json([
            'status'  => 'ok',
            'engine'  => Kernel::VERSION,
            'modules' => $modules,
        ]);
    });
}

function register_demo_routes(Kernel $kernel, \Core\Router $router, ConfigInterface $config): void
{
    $router->get('/', static function (RequestInterface $request) use ($kernel, $config): Response {
        /** @var ViewInterface $view */
        $view = $kernel->get(ViewInterface::class);
        $html = $view->render('home', [
            'title'   => 'LEX Engine · ' . (string) $config->get('engine.site_url', ''),
            'site'    => basename((string) $config->get('engine.site_dir', 'demo')),
            'version' => Kernel::VERSION,
        ]);
        return Response::html($html);
    });
}
