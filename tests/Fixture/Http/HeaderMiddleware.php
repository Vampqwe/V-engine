<?php
declare(strict_types=1);

namespace Tests\Fixture\Http;

use Core\Contract\MiddlewareInterface;
use Core\Contract\RequestInterface;
use Core\Response;

/**
 * Фикстура middleware: добавляет заголовок X-Trace-Slot (проверка слоёв pipeline).
 */
final class HeaderMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $slot)
    {
    }

    public function handle(RequestInterface $request, callable $next): object
    {
        $result = $next($request);
        if (!$result instanceof Response) {
            $result = Response::html((string) $result);
        }
        return $result->withHeader('X-Trace-Slot', $this->slot);
    }
}
