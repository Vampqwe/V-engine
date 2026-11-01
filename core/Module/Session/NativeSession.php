<?php
declare(strict_types=1);

namespace Core\Module\Session;

use Core\Contract\SessionInterface;

/**
 * Нативная сессия движка поверх любого SessionStore.
 *
 * Безопасность:
 *  - SID: ровно 32 hex (random_bytes(16)); чужой/битый cookie-id отбрасывается,
 *    генерируется новый — инъекция путей в ключ хранилища невозможна;
 *  - regenerate() — защита от session fixation;
 *  - unserialize данных с allowed_classes=false — object injection исключён.
 *
 * Хранение cookie-атрибутов (HttpOnly/SameSite) — ответственность слоя Cookie;
 * здесь только данные и жизненный цикл идентификатора.
 */
final class NativeSession implements SessionInterface
{
    public const VERSION = '1.0.0';

    private const ID_PATTERN = '/^[a-f0-9]{32}$/';

    /** @var array<string, mixed>|null null => данные ещё не загружены */
    private ?array $data = null;

    private string $sid;

    private bool $incomingValid;

    public function __construct(
        private readonly SessionStoreInterface $store,
        ?string $incomingId = null,
    ) {
        $this->incomingValid = $incomingId !== null && preg_match(self::ID_PATTERN, $incomingId) === 1;
        // Битый/отсутствующий cookie => новый сид; старый мусор не попадает в хранилище.
        $this->sid = $this->incomingValid ? $incomingId : bin2hex(random_bytes(16));
    }

    // =========================================================================
    // SessionInterface
    // =========================================================================

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $data = $this->data();
        $data[$key] = $value;
        $this->data = $data;
        $this->persist();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data());
    }

    public function remove(string $key): void
    {
        $data = $this->data();
        unset($data[$key]);
        $this->data = $data;
        $this->persist();
    }

    public function destroy(): void
    {
        $this->store->destroy($this->sid);
        $this->data = [];
    }

    public function regenerate(): void
    {
        $data = $this->data();
        $this->store->destroy($this->sid);
        $this->sid = bin2hex(random_bytes(16));
        $this->data = $data;
        $this->persist();
    }

    public function id(): string
    {
        return $this->sid;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** Есть ли валидный входящий SID из cookie (иначе слой Cookie ставит новый). */
    public function hasIncomingId(): bool
    {
        return $this->incomingValid;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        if ($this->data === null) {
            $this->data = $this->store->read($this->sid) ?? [];
        }
        return $this->data;
    }

    private function persist(): void
    {
        assert($this->data !== null);
        $this->store->write($this->sid, $this->data);
    }
}
