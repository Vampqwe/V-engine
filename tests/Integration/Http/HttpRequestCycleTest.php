<?php
declare(strict_types=1);

namespace Tests\Integration\Http;

use Core\Contract\EventBusInterface;
use Core\Contract\RequestInterface;
use Core\Contract\RouterInterface;
use Core\Exception\ContainerException;
use Core\Kernel;
use Core\Module\Config\ConfigModule;
use Core\Module\FileStorage\FileStorageModule;
use Core\Module\Logger\LoggerModule;
use Core\Request;
use Core\Response;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Http\HeaderMiddleware;
use Tests\Fixture\Http\RouteRegisteringModule;

/**
 * ШАГ 3 end-to-end: boot() поднимает EventBus/Registry/Router, run()
 * прогоняет полный HTTP-цикл со всеми хуками §17 и middleware-слоями.
 */
final class HttpRequestCycleTest extends TestCase
{
    private string $engineRoot;

    protected function setUp(): void
    {
        $this->engineRoot = sys_get_temp_dir() . '/lex-step3-' . bin2hex(random_bytes(4));
        mkdir($this->engineRoot . '/sites/example.test/config', 0o755, true);
        mkdir($this->engineRoot . '/sites/example.test/storage', 0o755, true);
        file_put_contents(
            $this->engineRoot . '/sites/example.test/config/core.php',
            "<?php\ndeclare(strict_types=1);\nreturn ['timezone' => 'UTC', 'debug' => false];",
        );
        file_put_contents(
            $this->engineRoot . '/sites/example.test/config/storage.php',
            "<?php\ndeclare(strict_types=1);\nreturn ['path' => '{$this->engineRoot}/sites/example.test/storage'];",
        );
        file_put_contents(
            $this->engineRoot . '/sites/example.test/.env',
            "SITE_URL=https://example.test\n",
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->engineRoot));
    }

    private function makeBootedKernel(bool $debug = false, callable ...$configure): Kernel
    {
        // CLI/тесты: домен задаётся через env SITE_DOMAIN (принятая модель
        // многосайтовости: SITE_URL/SITE_DOMAIN -> sites/<домен>).
        file_put_contents(
            $this->engineRoot . '/sites/example.test/config/core.php',
            "<?php\ndeclare(strict_types=1);\nreturn ['timezone' => 'UTC', 'debug' => " . ($debug ? 'true' : 'false') . '];',
        );
        $kernel = new Kernel($this->engineRoot, [
            new ConfigModule(),
            new FileStorageModule(),
            new LoggerModule(),
        ], [], ['SITE_DOMAIN' => 'example.test']);
        $kernel->addUserModule(new RouteRegisteringModule());
        foreach ($configure as $fn) {
            $fn($kernel);
        }
        $kernel->boot();
        return $kernel;
    }

    private function request(string $method, string $uri, array $query = []): Request
    {
        return Request::fromServer(
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri],
            $query,
        );
    }

    public function testBootRegistersStepThreeInfrastructure(): void
    {
        $kernel = $this->makeBootedKernel();
        self::assertInstanceOf(EventBusInterface::class, $kernel->getEventBus());
        self::assertInstanceOf(RouterInterface::class, $kernel->get(RouterInterface::class));
        self::assertTrue($kernel->getRegistry()->has('FixtureSite'));
        self::assertTrue($kernel->getRegistry()->has('Config'));
    }

    public function testHappyPathFiresAllHooksInOrder(): void
    {
        $kernel = $this->makeBootedKernel();
        $response = $kernel->run($this->request('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<h1>home</h1>', $response->getBody());
        self::assertSame('text/html; charset=utf-8', $response->getHeaders()['Content-Type']);
        self::assertSame([
            'start:/',
            'resolved:found',
            'before:200',
            'after:200',
            'end:200',
        ], RouteRegisteringModule::$eventLog);
    }

    public function testRouteParamsAndQueryReachHandler(): void
    {
        $kernel = $this->makeBootedKernel();
        $response = $kernel->run($this->request('GET', '/news/7?page=3', ['page' => 3]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 7, 'query' => 3], json_decode($response->getBody(), true));
    }

    public function testNotFoundShortCircuitsWithoutRenderHooks(): void
    {
        $kernel = $this->makeBootedKernel();
        $response = $kernel->run($this->request('GET', '/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertContains('start:/missing', RouteRegisteringModule::$eventLog);
        self::assertContains('resolved:not_found', RouteRegisteringModule::$eventLog);
        self::assertContains('end:404', RouteRegisteringModule::$eventLog);
        self::assertStringNotContainsString('before:404', implode(',', RouteRegisteringModule::$eventLog));
    }

    public function testMethodNotAllowedReturns405WithAllowHeader(): void
    {
        $kernel = $this->makeBootedKernel();
        // GET на POST-маршрут без middleware-резолва не доходит до pipeline
        $response = $kernel->run($this->request('GET', '/form'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaders()['Allow']);
    }

    public function testHandlerExceptionBecomes500AndFiresOnError(): void
    {
        $kernel = $this->makeBootedKernel();
        $response = $kernel->run($this->request('GET', '/boom'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', $response->getBody());
        self::assertContains('error:handler exploded', RouteRegisteringModule::$eventLog);
        self::assertContains('end:500', RouteRegisteringModule::$eventLog);
    }

    public function testUnsupportedHandlerReturnIsLoggedAsError(): void
    {
        $kernel = $this->makeBootedKernel(true);
        $response = $kernel->run($this->request('GET', '/bad-return'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('unsupported response type "int"', $response->getBody());
    }

    public function testGlobalMiddlewareWrapsResponse(): void
    {
        $kernel = $this->makeBootedKernel(false, static function (Kernel $k): void {
            $k->addMiddleware(new HeaderMiddleware('global'));
        });
        $response = $kernel->run($this->request('GET', '/'));
        self::assertSame('global', $response->getHeaders()['X-Trace-Slot']);
    }

    public function testRouteMiddlewareResolvedFromContainer(): void
    {
        $kernel = $this->makeBootedKernel(false, static function (Kernel $k): void {
            $k->addMiddleware(new HeaderMiddleware('global'));
        });
        // route-middleware регистрируется в контейнер после boot() (модуль сайта)
        $kernel->getContainer()->set('fixture.mw', static fn () => new HeaderMiddleware('route'));
        $response = $kernel->run($this->request('POST', '/form'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], json_decode($response->getBody(), true));
        // route-слой ближе к хендлеру → глобальный перезаписывает заголовок последним
        self::assertSame('global', $response->getHeaders()['X-Trace-Slot']);
    }

    public function testUnregisteredRouteMiddlewareYields500(): void
    {
        $kernel = $this->makeBootedKernel(true);
        $response = $kernel->run($this->request('POST', '/form'));
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Middleware service "fixture.mw" is not registered', $response->getBody());
    }

    public function testRunBeforeBootRejected(): void
    {
        $kernel = new Kernel($this->engineRoot, null, [], ['SITE_DOMAIN' => 'example.test']);
        $this->expectException(ContainerException::class);
        $kernel->run($this->request('GET', '/'));
    }

    public function testRequestInstanceAvailableViaContainer(): void
    {
        $kernel = new Kernel(
            $this->engineRoot,
            [new ConfigModule(), new FileStorageModule(), new LoggerModule()],
            ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test'],
            [],
        );
        $kernel->boot();
        // Request попадает в контейнер только внутри run() (§16.9) — до запроса
        // его там нет; после прогона цикла сервис доступен модулям.
        self::assertFalse($kernel->getContainer()->has(RequestInterface::class));
        $kernel->run(Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']));
        $request = $kernel->get(RequestInterface::class);
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/', $request->getPath());
    }

    public function testResponseFactoryHelpers(): void
    {
        self::assertSame(404, Response::notFound()->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', Response::notFound()->getHeaders()['Content-Type']);
        $json = Response::json(['a' => 'б'], 201);
        self::assertSame(201, $json->getStatusCode());
        self::assertSame('{"a":"б"}', $json->getBody());
        $mutated = Response::text('x')->withStatus(201)->withBody('y')->withHeader('x-a', '1');
        self::assertSame(201, $mutated->getStatusCode());
        self::assertSame('y', $mutated->getBody());
        self::assertSame('1', $mutated->getHeaders()['X-A']);
    }

    public function testRequestImmutabilityAndAttributes(): void
    {
        $request = Request::fromServer(
            ['REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/a/b/?x=1', 'HTTP_X_CUSTOM' => 'v', 'CONTENT_TYPE' => 'text/plain'],
            ['x' => 1],
            ['field' => 'val'],
        );
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/a/b', $request->getPath());
        self::assertSame('v', $request->getHeader('X-Custom'));
        self::assertSame('text/plain', $request->getHeader('content-type'));
        self::assertSame(1, $request->getQuery('x'));
        self::assertSame('val', $request->getPost('field'));
        self::assertNull($request->getQuery('nope'));
        self::assertSame('d', $request->getPost('nope', 'd'));

        $derived = $request->withAttribute('user', 5);
        self::assertNull($request->getAttribute('user'));
        self::assertSame(5, $derived->getAttribute('user'));
        self::assertSame([], $derived->getRouteParams());
    }
}
