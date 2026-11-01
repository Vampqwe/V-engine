<?php
declare(strict_types=1);

namespace Tests\Fixture\Http;

use Core\Contract\EventBusInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\RequestInterface;

use Core\Contract\RouterInterface;
use Core\Response;

/**
 * Фикстура-модуль уровня сайта: регистрирует маршруты в Router ядра и
 * слушатели на хуки §17 — ровно так, как это сделает реальный сайт (ШАГ 4+).
 */
final class RouteRegisteringModule implements ModuleInterface
{
    /** @var array<int, string> Записи о сработавших событиях (для ассертов) */
    public static array $eventLog = [];

    public function getName(): string
    {
        return 'FixtureSite';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return [RouterInterface::class, EventBusInterface::class];
    }

    /** @return string[] */
    public function getProvides(): array
    {
        return [];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $router = $container->get(RouterInterface::class);
        assert($router instanceof RouterInterface);
        $bus = $kernel->getEventBus();
        assert($bus !== null);

        self::$eventLog = [];

        $router->get('/', static fn (): string => '<h1>home</h1>');
        $router->get('/news/{id:\d+}', static fn (RequestInterface $r, string $id): Response => Response::json([
            'id'    => (int) $id,
            'query' => $r->getQuery('page', 1),
        ]));
        $router->post('/form', static fn (): array => ['ok' => true], ['fixture.mw']);
        $router->get('/boom', static function (): void {
            throw new \RuntimeException('handler exploded');
        });
        $router->get('/bad-return', static fn (): int => 42);

        $bus->listen('onRequestStart', static function (array $p): string {
            RouteRegisteringModule::$eventLog[] = 'start:' . $p['request']->getPath();
            return 's';
        }, 10);
        $bus->listen('onRouteResolved', static function (array $p): void {
            RouteRegisteringModule::$eventLog[] = 'resolved:' . $p['status'];
        });
        $bus->listen('onBeforeRender', static function (array $p): void {
            RouteRegisteringModule::$eventLog[] = 'before:' . $p['response']->getStatusCode();
        });
        $bus->listen('onAfterRender', static function (array $p): void {
            RouteRegisteringModule::$eventLog[] = 'after:' . $p['response']->getStatusCode();
        });
        $bus->listen('onRequestEnd', static function (array $p): void {
            RouteRegisteringModule::$eventLog[] = 'end:' . $p['response']->getStatusCode();
        });
        $bus->listen('onError', static function (array $p): void {
            RouteRegisteringModule::$eventLog[] = 'error:' . $p['exception']->getMessage();
        });
    }
}
