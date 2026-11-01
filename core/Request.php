<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\RequestInterface;

/**
 * Значение-обёртка входного HTTP-запроса (ШАГ 3).
 * Иммутабельный: with* возвращают копии. Суперглобалы читаются только
 * в fromServer() внутри ядра — пользовательский код видит лишь контракт.
 */
final class Request implements RequestInterface
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers Ключи в нижнем регистре.
     * @param array<string, mixed> $attributes
     * @param array<string, string> $routeParams
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $post,
        private readonly array $headers,
        private array $attributes,
        private array $routeParams,
    ) {
    }

    /**
     * Построить запрос из $_SERVER-подобных массивов (ядро вызывает его
     * в точке входа; в тестах передаётся вручную).
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, array<string, mixed>> $files сырой $_FILES-массив (нормализуется в DTO)
     */
    public static function fromServer(array $server, array $query = [], array $post = [], array $files = []): self
    {
        $method = strtoupper(trim((string) ($server['REQUEST_METHOD'] ?? 'GET'))) ?: 'GET';

        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $question = strpos($uri, '?');
        $path = $question === false ? $uri : substr($uri, 0, $question);

        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }

        return new self($method, self::normalizePath($path), $query, $post, $headers, [], []);
    }

    /**
     * Нормализация пути: decode → слэши → /segment/... (без хвостового слэша).
     * Пустая строка остаётся пустой — строгую форму паттерна проверяет Router.
     */
    public static function normalizePath(string $path): string
    {
        $decoded = rawurldecode($path);
        $unified = str_replace('\\', '/', $decoded);
        $segments = array_values(array_filter(explode('/', $unified), static fn ($s) => $s !== ''));
        if ($segments === []) {
            return $decoded === '' ? '' : '/';
        }
        return '/' . implode('/', $segments);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function getPost(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function withAttribute(string $name, mixed $value): RequestInterface
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /** @internal Используется только Kernel/Router при разрешении маршрута. */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;
        return $clone;
    }

    public function getRouteParams(): array
    {
        return $this->routeParams;
    }
}
