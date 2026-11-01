<?php
declare(strict_types=1);

namespace Core\Module\Session;

/**
 * Redis-хранилище сессий (opt-in, ext-redis). tryConnect() — единственная
 * легальная точка создания: недоступный redis возвращает null, и модуль
 * деградирует до file без ошибок (§7 Конституции).
 */
final class RedisSessionStore implements SessionStoreInterface
{
    private function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix,
        private readonly int $ttl,
    ) {
    }

    /** @return ?self null если расширение/сервер недоступны */
    public static function tryConnect(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $prefix = 'vengine:sess:',
        int $ttl = 86400,
    ): ?self {
        if (!class_exists(\Redis::class)) {
            return null;
        }
        try {
            $redis = new \Redis();
            if (!$redis->connect($host, $port, 1.5)) { // 1.5s — быстрый fail
                return null;
            }
            $redis->ping();
            return new self($redis, $prefix, $ttl);
        } catch (\Throwable) {
            return null;
        }
    }

    public function read(string $id): ?array
    {
        $raw = $this->redis->get($this->prefix . $id);
        if (!is_string($raw)) {
            return null;
        }
        $data = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($data) ? $data : null;
    }

    public function write(string $id, array $data): bool
    {
        return (bool) $this->redis->setex($this->prefix . $id, $this->ttl, serialize($data));
    }

    public function destroy(string $id): void
    {
        $this->redis->del($this->prefix . $id);
    }

    public function isHealthy(): bool
    {
        try {
            return $this->redis->ping() !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
