<?php
declare(strict_types=1);

namespace Core\Module\Session;

/**
 * Read-through миграция file <-> redis (принятое архитектурное решение).
 *
 * primary — целевой бэкенд (например redis после включения SESSION_DRIVER=redis);
 * legacy  — старый бэкенд (file), с которого данные мигрируют на первый запрос:
 *   read(id): primary ?? legacy -> если найден в legacy, пишем в primary.
 * write/dual-write гарантируют, что при откате на legacy пользователь не
 * потеряет сессию. При недоступности primary (redis упал) все операции
 * прозрачно падают на legacy — деградация без ошибок (§7 Конституции).
 */
final class MigratingSessionStore implements SessionStoreInterface
{
    public function __construct(
        private readonly SessionStoreInterface $primary,
        private readonly SessionStoreInterface $legacy,
    ) {
    }

    public function read(string $id): ?array
    {
        if ($this->primary->isHealthy()) {
            $data = $this->primary->read($id);
            if ($data !== null) {
                return $data;
            }
            // read-through миграция: тянем из legacy и осеменяем primary.
            $legacyData = $this->legacy->read($id);
            if ($legacyData !== null) {
                $this->primary->write($id, $legacyData);
            }
            return $legacyData;
        }
        return $this->legacy->read($id);
    }

    public function write(string $id, array $data): bool
    {
        $ok = true;
        if ($this->primary->isHealthy()) {
            $ok = $this->primary->write($id, $data);
        }
        // dual-write: legacy остаётся консистентным на случай отката драйвера.
        $this->legacy->write($id, $data);
        return $ok;
    }

    public function destroy(string $id): void
    {
        $this->primary->destroy($id);
        $this->legacy->destroy($id);
    }

    public function isHealthy(): bool
    {
        return $this->primary->isHealthy() || $this->legacy->isHealthy();
    }
}
