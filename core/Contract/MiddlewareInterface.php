<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт middleware-компонента (принятое решение: официальный контракт
 * Middleware нужен, т.к. §4 требует автоматическую защиту через pipeline).
 *
 * Pipeline строится в Kernel::run(); каждый компонент получает Request
 * и замыкание $next. Ответ — любой объект с getStatusCode()/getBody()/
 * getHeaders() (Kernel нормализует результат хендлера).
 */
interface MiddlewareInterface
{
    /**
     * @param callable(RequestInterface):object $next Следующее звено pipeline
     */
    public function handle(RequestInterface $request, callable $next): object;
}
