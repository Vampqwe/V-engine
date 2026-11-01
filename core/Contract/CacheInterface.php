<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт кэша (P1, §16.1).
 */
interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, int $ttl = 0): bool;

    public function has(string $key): bool;

    public function delete(string $key): bool;

    public function clear(): bool;
}
