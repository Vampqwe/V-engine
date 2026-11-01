<?php
declare(strict_types=1);

namespace Core\Module\Cache;

use Core\Contract\CacheInterface;
use Core\Contract\FileStorageInterface;

/**
 * P1-модуль Cache (§8.2.6): файловый кэш поверх jailed-root FileStorage.
 *
 * Формат значения: expire-ts (0 = бессрочно) + "\n" + serialize(data).
 * unserialize с allowed_classes=false — десериализация объектов исключена.
 * Ключи канонизируются (только [A-Za-z0-9_.-], длина <= 128) — инъекция
 * путей в имя файла невозможна.
 */
final class FileCache implements CacheInterface
{
    public const VERSION = '1.0.0';

    private const KEY_PATTERN = '/^[A-Za-z0-9_.\-]{1,128}$/';

    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly string $prefix = 'cache',
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->storage->load($this->path($key));
        if ($raw === null) {
            return $default;
        }
        $nl = strpos($raw, "\n");
        if ($nl === false) {
            return $default; // битый файл — трактуем как miss
        }
        $expire = (int) substr($raw, 0, $nl);
        if ($expire !== 0 && $expire < time()) {
            $this->storage->delete($this->path($key));
            return $default;
        }
        $data = @unserialize(substr($raw, $nl + 1), ['allowed_classes' => false]);
        return $data === false && trim(substr($raw, $nl + 1)) !== 'b:0;' ? $default : $data;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return false;
        }
        $expire = $ttl > 0 ? time() + $ttl : 0;
        return $this->storage->save($this->path($key), $expire . "\n" . serialize($value));
    }

    public function has(string $key): bool
    {
        return $this->get($key, new \stdClass()) instanceof \stdClass === false;
    }

    public function delete(string $key): bool
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return false;
        }
        return $this->storage->delete($this->path($key));
    }

    /** clear() удаляет только файлы кэша внутри своего префикса. */
    public function clear(): bool
    {
        foreach ($this->storage->list($this->prefix . '/*.cache') as $file) {
            if (!$this->storage->delete($file)) {
                return false;
            }
        }
        return true;
    }

    private function path(string $key): string
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new \Core\Exception\EngineException(sprintf('Invalid cache key "%s".', $key));
        }
        return $this->prefix . '/' . $key . '.cache';
    }
}
