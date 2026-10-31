<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт конфигурации (P0 — Config, Статьи 8.1/8.5/8.6/8.7).
 * Замечание к Конституции: отсутствует в перечне Статьи 16,
 * но императивно требуется статьями 8.x — см. IMPLEMENTATION.md §3.
 */
interface ConfigInterface
{
    /**
     * Значение по ключу «file.key» (точечная нотация), либо $default.
     */
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    /**
     * Всё содержимое конфигурационного файла (например, 'logger').
     *
     * @return array<string, mixed>
     */
    public function all(string $file): array;

    /**
     * Переменная окружения из .env сайта (SITE_BASE_FOLDER/.env).
     */
    public function env(string $key, ?string $default = null): ?string;
}
