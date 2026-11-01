<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\RouteResult;
use Core\Contract\RouterInterface;
use Core\Exception\EngineException;

/**
 * Маршрутизатор (ШАГ 3, §8.1 п.4, ERRATA-004).
 *
 * Синтаксис паттернов:
 *   /news/{id}          — обязательный параметр ([^/]+)
 *   /news/{id:\d+}      — параметр с regex-ограничителем
 *   /docs/{path:.*}     — жадный параметр (не содержит '/')? — нет: .* может
 *                        включать '/', именованные группы PHP совместимы.
 *
 * Разрешение чистое и предсказуемое: точные пути проверяются первыми,
 * затем шаблоны в порядке регистрации. resolve() НЕ вызывает хендлер —
 * это делает Kernel::run() после событий onRouteResolved и middleware.
 */
final class Router implements RouterInterface
{
    public const VERSION = '1.0.0';

    /** @var array<string, array<int, array{pattern:string, regex:?string, params:string[], handler:callable, middleware:string[]}>> method => routes */
    private array $routes = [];

    /** @var array<string, true> нормализованный "method|exact-path" => marker */
    private array $exactIndex = [];

    /** @var array<string, array<int, string>> exact path => keys в $routes */
    private array $exactRoutes = [];

    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $method = strtoupper(trim($method));
        if ($method === '') {
            throw new EngineException('Route method cannot be empty.');
        }
        $this->addCompiled($method, $pattern, $handler, $middleware);
    }

    public function get(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function match(array $methods, string $pattern, callable $handler, array $middleware = []): void
    {
        if ($methods === []) {
            throw new EngineException('Route must allow at least one HTTP method.');
        }
        foreach ($methods as $method) {
            $this->add($method, $pattern, $handler, $middleware);
        }
    }

    public function resolve(string $method, string $path): RouteResult
    {
        $method = strtoupper(trim($method)) ?: 'GET';
        $path = Request::normalizePath($path);

        // 1. Точные совпадения — быстрее и детерминированнее regex.
        $key = $method . '|' . $path;
        if (isset($this->exactIndex[$key])) {
            foreach ($this->exactRoutes[$path] ?? [] as $routeKey) {
                [$routeMethod, $index] = explode('#', $routeKey, 2);
                if ($routeMethod === $method) {
                    $route = $this->routes[$method][(int) $index];
                    return RouteResult::found($route['handler'], [], $route['middleware'], $route['pattern']);
                }
            }
        }

        // 2. Шаблоны: сканируем все методы, помечаем ALLOW для текущего пути.
        $allowed = [];
        foreach ($this->routes as $routeMethod => $list) {
            foreach ($list as $index => $route) {
                if ($route['regex'] === null) {
                    continue; // точные уже проверены выше
                }
                if (preg_match($route['regex'], $path, $m) === 1) {
                    if ($routeMethod === $method) {
                        $params = [];
                        foreach ($route['params'] as $name) {
                            $params[$name] = $m[$name] ?? '';
                        }
                        return RouteResult::found($route['handler'], $params, $route['middleware'], $route['pattern']);
                    }
                    $allowed[$routeMethod] = true;
                }
            }
        }

        // 3. Путь существует, но метод не тот → 405 (точечные маршруты тоже учтём).
        if ($allowed === []) {
            foreach ($this->exactRoutes[$path] ?? [] as $routeKey) {
                [$routeMethod] = explode('#', $routeKey, 2);
                if ($routeMethod !== $method) {
                    $allowed[$routeMethod] = true;
                }
            }
        }

        if ($allowed !== []) {
            return RouteResult::methodNotAllowed(array_keys($allowed));
        }

        return RouteResult::notFound();
    }

    /** @return string[] Зарегистрированные паттерны (для Doctor/отладки). */
    public function listPatterns(): array
    {
        $patterns = [];
        foreach ($this->routes as $method => $list) {
            foreach ($list as $route) {
                $patterns[] = $method . ' ' . $route['pattern'];
            }
        }
        return $patterns;
    }

    /**
     * @param string[] $middleware
     */
    private function addCompiled(string $method, string $pattern, callable $handler, array $middleware): void
    {
        if (!is_string($pattern) || $pattern === '' || !str_starts_with($pattern, '/')) {
            throw new EngineException(sprintf('Route pattern "%s" must start with "/".', $pattern));
        }
        // Паттерн НЕ декодируется из URL-encoding: он содержит regex-ограничителей (\d+ и т.п.),
        // которые rawurldecode() иначе уничтожит (%5Cd -> \d на входе, обратное искажение при round-trip).
        $normalized = self::normalizePattern($pattern);

        if (str_contains($normalized, '{')) {
            [$regex, $params] = self::compile($normalized);
        } else {
            $regex = null;
            $params = [];
        }

        $index = count($this->routes[$method] ?? []);
        $this->routes[$method][$index] = [
            'pattern'    => $normalized,
            'regex'      => $regex,
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];

        if ($regex === null) {
            $this->exactIndex[$method . '|' . $normalized] = true;
            $this->exactRoutes[$normalized][] = $method . '#' . $index;
        }
    }

    /**
     * Нормализация паттерна маршрута (без URL-decode — см. комментарий в addCompiled).
     * ВАЖНО: обратный слэш НЕ заменяется — он является частью regex-ограничителей
     * ({id:\d+}, {path:[a-z\/]+} и т.п.). Только схлопывание двойных прямых слэшей
     * и удаление хвостового слэша.
     */
    private static function normalizePattern(string $pattern): string
    {
        $unified = preg_replace('#/+#', '/', $pattern) ?? $pattern;
        $trimmed = rtrim($unified, '/');
        // Корневой путь '/' должен остаться '/' (rtrim превратил бы его в пустую строку).
        return $trimmed === '' ? '/' : $trimmed;
    }

    /**
     * Скомпилировать шаблон в regex с именованными группами.
     * Ограничитель по умолчанию: [^/]+.
     *
     * @return array{0: string, 1: string[]}
     */
    private static function compile(string $pattern): array
    {
        $params = [];
        $offset = 0;
        $regex = '';

        while (preg_match('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/', $pattern, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            /** @var array<int, array{0:string,1:int}> $m */
            $literal = substr($pattern, $offset, $m[0][1] - $offset);
            $regex .= preg_quote($literal, '#');

            $name = $m[1][0];
            $constraint = isset($m[2][0]) && $m[2][0] !== '' ? $m[2][0] : '[^/]+';
            if (in_array($name, $params, true)) {
                throw new EngineException(sprintf('Duplicate parameter "{%s}" in pattern "%s".', $name, $pattern));
            }
            $params[] = $name;
            $regex .= '(?P<' . $name . '>' . $constraint . ')';

            $offset = $m[0][1] + strlen($m[0][0]);
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        return ['#^' . $regex . '$#u', $params];
    }
}
