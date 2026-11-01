<?php
declare(strict_types=1);

namespace Core\Module\Session;

use Core\Contract\FileStorageInterface;

/**
 * Файловое хранилище сессий. Данные лежат строго внутри jailed-root
 * FileStorage (атомарная запись tmp+rename уже гарантирована им).
 * Имя файла = sid (валидируется регуляркой до обращения к диску).
 */
final class FileSessionStore implements SessionStoreInterface
{
    private const ID_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly string $prefix = 'sess_',
    ) {
    }

    public function read(string $id): ?array
    {
        if (!$this->valid($id)) {
            return null;
        }
        $raw = $this->storage->load($this->path($id));
        if ($raw === null) {
            return null;
        }
        $data = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($data) ? $data : null;
    }

    public function write(string $id, array $data): bool
    {
        if (!$this->valid($id)) {
            return false;
        }
        return $this->storage->save($this->path($id), serialize($data));
    }

    public function destroy(string $id): void
    {
        if ($this->valid($id)) {
            $this->storage->delete($this->path($id));
        }
    }

    public function isHealthy(): bool
    {
        // Запись тестового ключа и его удаление — единственная честная проверка.
        $probe = bin2hex(random_bytes(16));
        if (!$this->write($probe, ['p' => 1])) {
            return false;
        }
        $this->destroy($probe);
        return true;
    }

    private function valid(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    private function path(string $id): string
    {
        return $this->prefix . $id;
    }
}
