<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт маршрутизатора (ERRATA-004 к Конституции v4.0.0).
 * Статья 8.1 относит Router к P0-инфраструктуре, но интерфейс в Статье 16
 * не был объявлен — данный контракт закрывает ERRATA-004.
 *
 * Router чистый: не знает про HTTP-суперглобалы. На вход — метод и путь,
 * на выход — типизированный результат RouteResult. Пользовательские
 * обработчики получают запрос только через контракты ядра (§22.3).
 */
interface RouterInterface
{
    /** Зарегистрировать маршрут для одного HTTP-метода. */
    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void;

    /** GET-маршрут. */
    public function get(string $pattern, callable $handler, array $middleware = []): void;

    /** POST-маршрут. */
    public function post(string $pattern, callable $handler, array $middleware = []): void;

    /** Маршрут для нескольких методов (METHOD|PATTERN синтаксис). */
    public function match(array $methods, string $pattern, callable $handler, array $middleware = []): void;

    /**
     * Разрешить путь против реестра маршрутов. НЕ выполняет хендлер —
     * выполнение остаётся за Kernel::run() (контроль событий и middleware).
     */
    public function resolve(string $method, string $path): RouteResult;
}
