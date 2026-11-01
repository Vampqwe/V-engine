<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт cookie (P1, §16.1). Значения подписываются HMAC — куки нельзя
 * подделать на клиенте.
 */
interface CookieInterface
{
    /** @return ?string декодированное значение или null (нет/подделано) */
    public function get(string $name): ?string;

    public function set(string $name, string $value, int $ttl = 0): void;

    public function remove(string $name): void;

    public function has(string $name): bool;
}
