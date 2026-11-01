<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт сессий (P1, §16.1). Хранение: file + redis с read-through
 * миграцией (принятое решение архитектуры).
 */
interface SessionInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;

    public function destroy(): void;

    /** Ротация ID сессии (защита от session fixation). */
    public function regenerate(): void;

    public function id(): string;
}
