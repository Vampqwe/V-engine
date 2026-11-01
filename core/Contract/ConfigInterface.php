<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт конфигурации (ERRATA-001, Статья 8.1 — P0).
 * Единый источник настроек: config-файлы ядра + .env сайта.
 * Доступ к значениям вне ConfigInterface запрещён (Конституция, раздел о конфиге).
 */
interface ConfigInterface
{
    /**
     * Получить значение по «точечному» ключу: 'logger.path', 'app.debug'.
     * Секции разделяются первой точкой: секция = имя config-файла без расширения.
     */
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    /** Вся секция целиком (массив). Пустая секция => []. */
    public function all(?string $section = null): array;
}
