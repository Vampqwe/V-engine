<?php
declare(strict_types=1);

namespace Tests\Unit\Router;

use Core\Contract\RouteResult;
use Core\Contract\RouterInterface;
use Core\Exception\EngineException;
use Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testImplementsContract(): void
    {
        self::assertInstanceOf(RouterInterface::class, $this->router);
    }

    public function testExactMatchReturnsHandlerAndPattern(): void
    {
        $handler = static fn (): string => 'home';
        $this->router->get('/', $handler, ['auth']);

        $result = $this->router->resolve('GET', '/');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertTrue($result->isFound());
        self::assertSame('home', ($result->handler)());
        self::assertSame(['auth'], $result->middleware);
        self::assertSame('/', $result->pattern);
    }

    public function testTrailingSlashIsNormalized(): void
    {
        $this->router->get('/about', static fn (): string => 'about');
        $result = $this->router->resolve('GET', '/about/');
        self::assertSame(RouteResult::FOUND, $result->status);
    }

    public function testParameterExtraction(): void
    {
        $this->router->get('/news/{id}', static fn () => 'news');
        $result = $this->router->resolve('GET', '/news/42');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertSame(['id' => '42'], $result->params);
    }

    public function testConstrainedParameter(): void
    {
        $this->router->get('/news/{id:\d+}', static fn () => 'news');
        self::assertSame(RouteResult::NOT_FOUND, $this->router->resolve('GET', '/news/abc')->status);
        self::assertSame(RouteResult::FOUND, $this->router->resolve('GET', '/news/123')->status);
    }

    public function testGreedyParameter(): void
    {
        $this->router->get('/docs/{path:.*}', static fn () => 'doc');
        $result = $this->router->resolve('GET', '/docs/a/b/c');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertSame(['path' => 'a/b/c'], $result->params);
    }

    public function testExactRouteWinsOverTemplate(): void
    {
        $this->router->get('/page/{slug}', static fn () => 'template');
        $this->router->get('/page/about', static fn () => 'exact');

        $result = $this->router->resolve('GET', '/page/about');
        self::assertSame('exact', ($result->handler)());
    }

    public function testNotFound(): void
    {
        $this->router->get('/exists', static fn () => 1);
        $result = $this->router->resolve('GET', '/missing');
        self::assertSame(RouteResult::NOT_FOUND, $result->status);
        self::assertNull($result->handler);
    }

    public function testMethodNotAllowedWithAllowedList(): void
    {
        $this->router->post('/form', static fn () => 1);
        $this->router->match(['PUT', 'PATCH'], '/form/{id}', static fn () => 2);

        $result = $this->router->resolve('DELETE', '/form');
        self::assertSame(RouteResult::METHOD_NOT_ALLOWED, $result->status);
        self::assertSame(['POST'], $result->allowedMethods);

        $result = $this->router->resolve('GET', '/form/5');
        self::assertSame(RouteResult::METHOD_NOT_ALLOWED, $result->status);
        sort($result->allowedMethods);
        self::assertSame(['PATCH', 'PUT'], $result->allowedMethods);
    }

    public function testCaseInsensitiveMethodResolution(): void
    {
        $this->router->add('get', '/x', static fn () => 1);
        self::assertSame(RouteResult::FOUND, $this->router->resolve('GET', '/x')->status);
    }

    public function testPatternWithoutLeadingSlashRejected(): void
    {
        $this->expectException(EngineException::class);
        $this->router->get('bad-pattern', static fn () => 1);
    }

    public function testDuplicateParamNameRejected(): void
    {
        $this->expectException(EngineException::class);
        $this->router->get('/a/{id}/b/{id}', static fn () => 1);
    }

    public function testEmptyMethodsRejected(): void
    {
        $this->expectException(EngineException::class);
        $this->router->match([], '/x', static fn () => 1);
    }

    public function testRegexMetacharsInLiteralAreEscaped(): void
    {
        $this->router->get('/v1.0/status', static fn () => 'ok');
        // '.' не должен работать как «любой символ»: /v1X0/status мимо
        self::assertSame(RouteResult::NOT_FOUND, $this->router->resolve('GET', '/v1X0/status')->status);
        self::assertSame(RouteResult::FOUND, $this->router->resolve('GET', '/v1.0/status')->status);
    }

    public function testListPatternsForDoctor(): void
    {
        $this->router->get('/', static fn () => 1);
        $this->router->post('/login', static fn () => 2);
        self::assertSame(['GET /', 'POST /login'], $this->router->listPatterns());
    }
}
