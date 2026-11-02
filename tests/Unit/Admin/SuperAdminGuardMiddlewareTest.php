<?php
declare(strict_types=1);

namespace Tests\Unit\Admin;

use Core\Contract\RequestInterface;
use Core\Module\Admin\AdminModule;
use Core\Module\Admin\SuperAdminAuth;
use Core\Module\Admin\SuperAdminGuardMiddleware;
use Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * §8.4: guard-привод панели — закрытие системного пространства путей,
 * исключения входа/выхода, fallback-обслуживание при отсутствии сайта.
 */
final class SuperAdminGuardMiddlewareTest extends TestCase
{
    private function request(string $method, string $uri, ?string $cookie = null, array $post = []): RequestInterface
    {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri];
        if ($cookie !== null) {
            $server['HTTP_COOKIE'] = $cookie;
        }
        return Request::fromServer($server, [], $post)
            ->withAttribute('client.ip', '203.0.113.1');
    }

    public function testNonSystemPathPassesThrough(): void
    {
        $guard = new SuperAdminGuardMiddleware(null);
        $sentinel = new \stdClass();
        $result = $guard->handle(
            $this->request('GET', '/about'),
            static fn (): object => $sentinel,
        );
        self::assertSame($sentinel, $result, 'вне панели middleware прозрачен');
    }

    public function testProtectedPathWithoutSessionGets401LoginPage(): void
    {
        $guard = new SuperAdminGuardMiddleware(null);
        $response = $guard->handle(
            $this->request('GET', AdminModule::SYSTEM_PATH . '/'),
            static fn (): object => throw new \LogicException('must not reach next()'),
        );
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('admin:passwd', (string) $response->getBody(), 'подсказка CLI без ключа');
    }

    public function testLegacyAdminPrefixAlsoGuarded(): void
    {
        $guard = new SuperAdminGuardMiddleware(null);
        $response = $guard->handle(
            $this->request('GET', AdminModule::PATH . '/modules'),
            static fn (): object => throw new \LogicException('must not reach next()'),
        );
        self::assertSame(401, $response->getStatusCode());
    }

    public function testLoginRouteIsPublicEntry(): void
    {
        $guard = new SuperAdminGuardMiddleware(null);
        $sentinel = new \stdClass();
        $result = $guard->handle(
            $this->request('GET', AdminModule::SYSTEM_PATH . '/login'),
            static fn (): object => $sentinel,
        );
        self::assertSame($sentinel, $result, 'страница входа доступна без сессии');
    }

    public function testValidSessionReachesNext(): void
    {
        $auth = $this->createMock(\Core\Contract\SuperAdminAuthenticatorInterface::class);
        $auth->method('validateToken')->willReturnCallback(static fn (?string $t): bool => $t === 'good-token');
        $auth->method('isConfigured')->willReturn(true);
        $guard = new SuperAdminGuardMiddleware($auth);

        $sentinel = new \stdClass();
        $result = $guard->handle(
            $this->request('GET', AdminModule::SYSTEM_PATH . '/', SuperAdminAuth::COOKIE_NAME . '=good-token'),
            static fn (): object => $sentinel,
        );
        self::assertSame($sentinel, $result);

        // Плохой токен — снова 401.
        $response = $guard->handle(
            $this->request('GET', AdminModule::SYSTEM_PATH . '/', SuperAdminAuth::COOKIE_NAME . '=bad'),
            static fn (): object => $sentinel,
        );
        self::assertSame(401, $response->getStatusCode());
    }
}
