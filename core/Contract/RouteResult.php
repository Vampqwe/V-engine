<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Результат разрешения маршрута (типизированный, без магических null).
 * Статусы: FOUND / NOT_FOUND / METHOD_NOT_ALLOWED.
 */
final class RouteResult
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';

    /**
     * @param string[] $allowedMethods Для METHOD_NOT_ALLOWED — список допустимых методов.
     * @param array<string, string> $params Параметры из плейсхолдеров {name}.
     * @param string[] $middleware Middleware маршрута (имена сервисов контейнера).
     * @param ?\Closure $handler Приведённый callable хендлера (property не может
     *                           иметь тип `callable` — храним Closure, вызываем как callback).
     */
    private function __construct(
        public readonly string $status,
        public readonly ?\Closure $handler = null,
        public readonly array $params = [],
        public readonly array $allowedMethods = [],
        public readonly array $middleware = [],
        public readonly string $pattern = '',
    ) {
    }

    public static function found(
        callable $handler,
        array $params,
        array $middleware,
        string $pattern,
    ): self {
        return new self(self::FOUND, \Closure::fromCallable($handler), $params, [], $middleware, $pattern);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    /** @param string[] $allowedMethods */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(self::METHOD_NOT_ALLOWED, null, [], $allowedMethods);
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}
