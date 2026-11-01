<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\ConfigInterface;
use Core\Contract\ContainerInterface;
use Core\Contract\EventBusInterface;
use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\MiddlewareInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\PaginatorInterface;
use Core\Contract\RequestInterface;
use Core\Contract\RouterInterface;
use Core\Contract\RouteResult;
use Core\Contract\SecurityInterface;
use Core\Contract\SessionInterface;
use Core\Contract\SiteContextInterface;
use Core\Contract\ValidatorInterface;
use Core\Contract\ViewInterface;
use Core\Contract\CookieInterface;
use Core\Contract\DatabaseInterface;
use Core\Contract\CacheInterface;
use Core\Exception\ContainerException;
use Closure;
use Core\Exception\EngineException;
use Core\Module\Cache\CacheModule;
use Core\Module\Config\ConfigModule;
use Core\Module\Cookie\CookieModule;
use Core\Module\Database\DatabaseModule;
use Core\Module\FileStorage\FileStorageModule;
use Core\Module\FileUpload\FileUploadModule;
use Core\Module\Logger\LoggerModule;
use Core\Module\Paginator\PaginatorModule;
use Core\Module\Security\SecurityModule;
use Core\Module\Session\SessionModule;
use Core\Module\Validator\ValidatorModule;
use Core\Module\View\ViewModule;

/**
 * Ядро движка (ШАГ 2–3, Статья 16.8).
 *
 * Жизненный цикл:
 *   boot() — детект сайта, загрузка P0/P1-модулей в порядке зависимостей,
 *            регистрация инфраструктуры (EventBus/Registry/Router),
 *            применение базовых настроек (timezone/debug).
 *   run()  — полный HTTP-цикл: onRequestStart -> resolve -> middleware
 *            pipeline -> handler -> onBeforeRender/onAfterRender -> Response.
 *   send() — единственное место, где ответ превращается в HTTP-выход (§31).
 *
 * Порядок загрузки модулей вычисляется топосортировкой графа «зависимости =>
 * предоставляемые контракты» (явный граф вместо магических уровней, §8.6).
 * Циклы отклоняются с указанием пути.
 */
final class Kernel implements KernelInterface
{
    public const VERSION = '0.3.0';

    private ContainerInterface $container;

    /** @var ModuleInterface[] Системные модули (детерминированный набор) */
    private array $systemModules;

    /** @var ModuleInterface[] Пользовательские модули */
    private array $userModules = [];

    private Registry $registry;

    private ?Router $router = null;

    /** @var MiddlewareInterface[] Глобальный middleware-слой (внешний — первым) */
    private array $globalMiddleware = [];

    private bool $booted = false;

    /** @param ?ModuleInterface[] $systemModules null => стандартный P0+P1-набор */
    public function __construct(
        private readonly string $engineRoot,
        ?array $systemModules = null,
        private readonly array $server = [],
        private readonly array $env = [],
    ) {
        $this->systemModules = $systemModules ?? self::defaultSystemModules();
    }

    /**
     * Набор системных модулей по умолчанию: P0 ядро + инфраструктура ШАГА 3
     * (посредством регистрации сервисов в boot()) + P1-модули ШАГА 4.
     * Database деградирует без конфига (§ сайт живёт без БД).
     *
     * @return ModuleInterface[]
     */
    public static function defaultSystemModules(): array
    {
        return [
            new ConfigModule(),       // ConfigInterface
            new FileStorageModule(),  // FileStorageInterface
            new LoggerModule(),       // LoggerInterface
            new CookieModule(),       // CookieInterface
            new SecurityModule(),     // SecurityInterface (зависит от Session+Config)
            new SessionModule(),      // SessionInterface
            new DatabaseModule(),     // DatabaseInterface (опционально, PDO-degradation)
            new ViewModule(),         // ViewInterface
            new CacheModule(),        // CacheInterface
            new ValidatorModule(),    // ValidatorInterface
            new PaginatorModule(),    // PaginatorInterface
            new FileUploadModule(),   // FileUploadInterface (P2, требует Config+FileStorage)
        ];
    }

    // =========================================================================
    // Boot
    // =========================================================================

    public function boot(): void
    {
        if ($this->booted) {
            throw new ContainerException('Kernel already booted.');
        }

        $this->container = new Container();

        // 1. Инфраструктурные instance — до модулей (не являются «модулями»).
        $site = SiteContext::detect($this->engineRoot, $this->server, $this->env);
        $this->container->instance(SiteContextInterface::class, $site);
        $this->container->instance(KernelInterface::class, $this);
        $this->container->instance(ContainerInterface::class, $this->container);

        // 2. Инфраструктура ШАГА 3: EventBus, Registry, Router — до модулей,
        //    чтобы пользовательские модули могли от них зависеть (§17, §25).
        $eventBus = new EventBus(function (): ?LoggerInterface {
            // Лениво: Logger может ещё не быть поднят на раннем этапе.
            return $this->container->has(LoggerInterface::class)
                ? $this->container->get(LoggerInterface::class)
                : null;
        });
        $this->container->instance(EventBusInterface::class, $eventBus);

        $this->registry = new Registry();
        $this->container->instance(Registry::class, $this->registry);

        $this->router = new Router();
        $this->container->instance(RouterInterface::class, $this->router);
        $this->container->instance(Router::class, $this->router);

        // 3. Системные модули в порядке топосортировки графа зависимостей.
        //    Внутри boot() ядро ещё не поднято — модули получают контейнер
        //    напрямую через getContainer(), а доступ к сервисам — из замыканий фабрик.
        foreach ($this->topologicalSort($this->systemModules) as $module) {
            $this->bootModule($module);
        }

        // 4. Пользовательские модули (зарегистрированы до boot()).
        foreach ($this->topologicalSort($this->userModules) as $module) {
            $this->bootModule($module);
        }

        // 5. Ядро поднято: внешние потребители получают доступ к сервисам.
        $this->booted = true;

        // 6. Базовые настройки из конфига + стартовая запись в лог.
        $config = $this->container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);
        date_default_timezone_set((string) $config->get('core.timezone', 'UTC'));

        $logger = $this->container->get(LoggerInterface::class);
        assert($logger instanceof LoggerInterface);
        $logger->info('kernel.booted', [
            'version' => self::VERSION,
            'site'    => $site->getDomain(),
        ]);
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    private function bootModule(ModuleInterface $module): void
    {
        // Понятная ошибка до boot(): отсутствие зависимости внутри чужого модуля
        // не должно выглядывать как NotFoundException из ниоткуда.
        foreach ($module->getDependencies() as $dependency) {
            if (!$this->container->has($dependency)) {
                throw new ContainerException(sprintf(
                    'Module "%s" requires missing dependency "%s".',
                    $module->getName(),
                    $dependency,
                ));
            }
        }
        $module->boot($this);
        // Реестр (§25): срез для Doctor/HealthCheck после успешного boot().
        $this->registry->register($module);
    }

    // =========================================================================
    // Middleware / Registry (ШАГ 3)
    // =========================================================================

    /** Добавить глобальный middleware-слой (до boot() или сразу после). */
    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        if ($this->booted) {
            throw new ContainerException('addMiddleware() must be called before boot().');
        }
        $this->globalMiddleware[] = $middleware;
    }

    public function getRegistry(): Registry
    {
        if (!isset($this->registry)) {
            throw new ContainerException('Kernel is not booted. Call boot() first.');
        }
        return $this->registry;
    }

    // =========================================================================
    // KernelInterface
    // =========================================================================

    public function addUserModule(ModuleInterface $module): void
    {
        if ($this->booted) {
            throw new ContainerException(sprintf(
                'Cannot register module "%s" after boot.',
                $module->getName(),
            ));
        }
        foreach ($this->userModules as $existing) {
            if ($existing->getName() === $module->getName()) {
                throw new ContainerException(sprintf('Module "%s" is already registered.', $module->getName()));
            }
        }
        $this->userModules[] = $module;
    }

    public function getContainer(): ContainerInterface
    {
        // Контейнер доступен и во время boot() (модули регистрируют в нём сервисы);
        // до boot() контейнера не существует — ошибка использования.
        if (!isset($this->container)) {
            throw new ContainerException('Kernel is not booted. Call boot() first.');
        }
        return $this->container;
    }

    public function getEventBus(): ?EventBusInterface
    {
        if (!isset($this->container)) {
            return null;
        }
        $bus = $this->container->get(EventBusInterface::class);
        assert($bus instanceof EventBusInterface);
        return $bus;
    }

    public function get(string $interface): mixed
    {
        $this->assertBooted();
        return $this->getContainer()->get($interface);
    }

    // =========================================================================
    // HTTP-цикл (ШАГ 3, §16.9, §17, §31)
    // =========================================================================

    /**
     * Прогнать запрос через полный цикл и вернуть ответ. НИЧЕГО не выводит —
     * вывод делает только send(). Исключения хендлера превращаются в 500
     * (и логируются), а не выпрыгивают наружу (§31).
     */
    public function run(RequestInterface $request): Response
    {
        $this->assertBooted();
        $bus = $this->getEventBus();
        assert($bus !== null);
        $logger = $this->container->get(LoggerInterface::class);
        assert($logger instanceof LoggerInterface);

        // Текущий запрос доступен сервисам через контейнер (Session/Cookie/View).
        $this->container->instance(RequestInterface::class, $request);

        try {
            $bus->dispatch('onRequestStart', ['request' => $request]);

            $result = $this->router->resolve($request->getMethod(), $request->getPath());
            $bus->dispatch('onRouteResolved', [
                'request' => $request,
                'result'  => $result,
                'status'  => $result->status,
            ]);

            $response = match ($result->status) {
                RouteResult::FOUND => $this->handleFound($request, $result, $bus, $logger),
                RouteResult::METHOD_NOT_ALLOWED => Response::methodNotAllowed($result->allowedMethods),
                default => Response::notFound(),
            };
        } catch (\Throwable $e) {
            // Аварийный люк цикла: 500 вместо сырого исключения (§31).
            $logger->error('kernel.run.crashed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);
            $bus->dispatch('onError', ['request' => $request, 'exception' => $e]);
            $debug = (bool) $this->container->get(ConfigInterface::class)->get('core.debug', false);
            $response = new Response(
                500,
                $debug ? $e->getMessage() : 'Internal Server Error',
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        $bus->dispatch('onRequestEnd', ['request' => $request, 'response' => $response]);
        return $response;
    }

    private function handleFound(RequestInterface $request, RouteResult $result, EventBusInterface $bus, LoggerInterface $logger): Response
    {
        $request = $request->withRouteParams($result->params);

        // Pipeline: глобальные слои снаружи, route-слои внутри (ближе к хендлеру).
        $layers = array_merge($this->globalMiddleware, $this->resolveRouteMiddleware($result->middleware));
        $core = fn (RequestInterface $r): object => $this->invokeHandler($r, $result, $bus);

        $next = $core;
        foreach (array_reverse($layers) as $layer) {
            $current = $next;
            $next = static fn (RequestInterface $r): object => $layer->handle($r, $current);
        }

        $produced = $next($request);
        return $this->toResponse($produced, $bus, $request);
    }

    /** @return MiddlewareInterface[] */
    private function resolveRouteMiddleware(array $names): array
    {
        $layers = [];
        foreach ($names as $name) {
            if (!$this->container->has($name)) {
                throw new EngineException(sprintf('Middleware service "%s" is not registered.', $name));
            }
            $mw = $this->container->get($name);
            if (!$mw instanceof MiddlewareInterface) {
                throw new EngineException(sprintf('Middleware service "%s" must implement MiddlewareInterface.', $name));
            }
            $layers[] = $mw;
        }
        return $layers;
    }

    private function invokeHandler(RequestInterface $request, RouteResult $result, EventBusInterface $bus): object
    {
        $handler = $result->handler;
        assert($handler !== null);
        $reflection = new \ReflectionFunction(Closure::fromCallable($handler));
        $args = [$request];
        foreach ($reflection->getParameters() as $index => $param) {
            if ($index === 0) {
                continue; // сам запрос уже передан
            }
            $name = $param->getName();
            if (!array_key_exists($name, $result->params)) {
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                    continue;
                }
                throw new EngineException(sprintf(
                    'Route parameter "$%s" is not present in matched params for pattern "%s".',
                    $name,
                    $result->pattern,
                ));
            }
            $value = $result->params[$name];
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !isset(self::PARAM_CASTS[$type->getName()])) {
                // Не «нативные» типы не кастим silently — параметризованные closure
                // вне нативных типов ловятся здесь же понятной ошибкой.
                throw new EngineException(sprintf(
                    'Unsupported handler param type "%s" for "$%s" (route "%s").',
                    $type->getName(),
                    $name,
                    $result->pattern,
                ));
            }
            $args[] = $type instanceof \ReflectionNamedType
                ? self::castParam($value, $type->getName())
                : $value;
        }
        // Вызываем хендлер ОДИН раз (§18: никаких повторных side-effect-вызовов);
        // объект (Response) пропускаем как есть, скаляр — оборачиваем.
        $returned = $handler(...$args);
        return is_object($returned) ? $returned : $this->wrapScalar($returned);
    }

    /** Нативно поддерживаемые типы параметров маршрута (скалярная коэрция строк regex). */
    private const PARAM_CASTS = ['string' => true, 'int' => true, 'float' => true, 'bool' => true];

    private static function castParam(string $value, string $type): mixed
    {
        return match ($type) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => $value !== '' && $value !== '0' && $value !== 'false',
            default => $value,
        };
    }

    /** Скалярный возврат хендлера => Response (string — html, массив — json). */
    private function wrapScalar(mixed $returned): object
    {
        if (is_string($returned)) {
            return Response::html($returned);
        }
        if (is_array($returned)) {
            return Response::json($returned);
        }
        // Неожиданный тип — сигнал ошибки, а не тихая подмена (§18).
        throw new EngineException(sprintf('unsupported response type "%s"', get_debug_type($returned)));
    }

    private function toResponse(object $produced, EventBusInterface $bus, RequestInterface $request): Response
    {
        if (!$produced instanceof Response) {
            // Middleware вернул не Response — тоже ошибка типа ответа.
            throw new EngineException(sprintf('unsupported response type "%s"', get_debug_type($produced)));
        }

        $final = $this->applyRenderHook($bus, $request, $produced);
        $bus->dispatch('onAfterRender', ['request' => $request, 'response' => $final]);
        return $final;
    }

    /**
     * onBeforeRender слушатель может вернуть модифицированный Response —
     * берётся последний ненулевой возврат цепочки (иначе оригинал).
     */
    private function applyRenderHook(EventBusInterface $bus, RequestInterface $request, Response $response): Response
    {
        $dispatched = $bus->dispatch('onBeforeRender', ['request' => $request, 'response' => $response]);
        foreach (array_reverse($dispatched['results']) as $result) {
            if ($result instanceof Response) {
                return $result;
            }
        }
        return $response;
    }

    /**
     * Единственное место, где ответ превращается в HTTP-выход (§31).
     * headers_response() + echo — никакого пользовательского вывода.
     */
    public function send(Response $response): void
    {
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $response->getBody();
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function assertBooted(): void
    {
        if (!$this->booted) {
            throw new ContainerException('Kernel is not booted. Call boot() first.');
        }
    }

    /**
     * Стабильная топосортировка (DFS, постобходание). Модуль идёт после всех
     * модулей, предоставляющих его контрактные зависимости. Провайдер
     * определяется по getProvides() (ModuleInterface) либо по согласованному
     * имени модуля ('Logger' <=> LoggerInterface::class).
     *
     * @param ModuleInterface[] $modules
     * @return ModuleInterface[]
     */
    private function topologicalSort(array $modules): array
    {
        $byName = [];
        foreach ($modules as $module) {
            $byName[$module->getName()] = $module;
        }

        /** @var array<string, string> contract => provider name */
        $providers = [];
        foreach ($byName as $name => $module) {
            foreach (self::providesOf($module) as $contract) {
                $providers[$contract] = $name;
            }
        }

        $state = [];   // name => 0 visiting | 1 done
        $ordered = [];
        $stack = [];

        $visit = function (string $name) use (&$visit, &$state, &$ordered, &$stack, $byName, $providers): void {
            if (($state[$name] ?? -1) === 1) {
                return;
            }
            if (($state[$name] ?? -1) === 0) {
                $cycle = array_slice($stack, array_search($name, $stack, true));
                $cycle[] = $name;
                throw new ContainerException('Circular module dependencies: ' . implode(' -> ', $cycle));
            }
            $state[$name] = 0;
            $stack[] = $name;

            foreach ($byName[$name]->getDependencies() as $contract) {
                $provider = $providers[$contract] ?? null;
                if ($provider !== null && $provider !== $name) {
                    $visit($provider);
                }
            }

            array_pop($stack);
            $state[$name] = 1;
            $ordered[] = $byName[$name];
        };

        foreach (array_keys($byName) as $name) {
            $visit((string) $name);
        }
        return $ordered;
    }

    /**
     * Контракты, которые предоставляет модуль: явный getProvides() или
     * соглашение «имя модуля <=> суффикс контракта» (Logger => LoggerInterface).
     *
     * @return string[]
     */
    private static function providesOf(ModuleInterface $module): array
    {
        if (method_exists($module, 'getProvides')) {
            /** @var string[] $explicit */
            $explicit = $module->getProvides();
            return $explicit;
        }
        // Соглашение по имени: 'FileStorage' => Core\Contract\FileStorageInterface::class
        $fqcn = 'Core\\Contract\\' . $module->getName() . 'Interface';
        if (interface_exists($fqcn)) {
            return [$fqcn];
        }
        return [];
    }
}
