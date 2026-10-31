<?php
declare(strict_types=1);

namespace Core;

/**
 * P0: Маршрутизатор ядра (Статья 19). Внутренний компонент — пользователь
 * не создаёт его напрямую, только через Kernel (Статьи 11, 23).
 */
final class Router
{
    /** @var array<string, array<string, string>> method -> path -> handler */
    private array $routes = [];

    /** @var array<string, array<int, array{pattern:string, regex:string, params:list<string>, handler:string}>> */
    private array $compiled = [];

    /** Регистрирует маршрут. Путь может содержать параметры {name}. */
    public function add(string $method, string $path, string $handler): void
    {
        $method = strtoupper($method);
        if (!preg_match('#^/[A-Za-z0-9_\-./{}]*$#', $path)) {
            throw new \InvalidArgumentException("Некорректный путь маршрута: {$path}");
        }
        if (!class_exists($handler)) {
            throw new \InvalidArgumentException("Обработчик маршрута не найден: {$handler}");
        }
        $this->routes[$method][$path] = $handler;
        $this->compile($method, $path, $handler);
    }

    public function get(string $path, string $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /**
     * Разрешает запрос в обработчик. null — если маршрут не найден (404).
     * @return array{handler:string, params:array<string,string>}|null
     */
    public function resolve(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/';
        }
        // Точное совпадение
        if (isset($this->routes[$method][$path])) {
            return ['handler' => $this->routes[$method][$path], 'params' => []];
        }
        // Параметрические маршруты
        foreach ($this->compiled[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches) === 1) {
                $params = [];
                foreach ($route['params'] as $name) {
                    $params[$name] = $matches[$name] ?? '';
                }
                return ['handler' => $route['handler'], 'params' => $params];
            }
        }
        return null;
    }

    /** @return list<string> Зарегистрированные пути (для Doctor/Admin). */
    public function listRoutes(): array
    {
        $list = [];
        foreach ($this->routes as $method => $paths) {
            foreach (array_keys($paths) as $path) {
                $list[] = "{$method} {$path}";
            }
        }
        sort($list);
        return $list;
    }

    private function compile(string $method, string $path, string $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $path
        ) ?? $path;
        $this->compiled[$method][] = [
            'pattern' => $path,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }
}
