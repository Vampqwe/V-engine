<?php
declare(strict_types=1);

namespace Core\Module\MaintenanceMode;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\HealthCheckInterface;
use Core\Contract\MaintenanceModeInterface;
use Core\Exception\PortabilityException;

/**
 * MaintenanceMode (P3, §8.4). Состояние — один JSON-файл в jailed-root
 * (`maintenance.json`), записывается атомарно через FileStorage (§20).
 *
 * Bypass: секретный токен из конфига `maintenance.bypass_token`. Токен
 * сравнивается через hash_equals — время утечки исключено (§33.2-style).
 * Без настроенного токена bypass невозможен никогда.
 */
final class FileMaintenanceMode implements MaintenanceModeInterface, HealthCheckInterface
{
    public const VERSION = '1.0.0';

    private const STATE_PATH = 'maintenance.json';

    /** Кэш состояния за время жизни экземпляра (дешёвый active() на каждый запрос). */
    private ?array $cached = null;

    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly ConfigInterface $config,
    ) {
    }

    public function active(): bool
    {
        return $this->read()['active'] === true;
    }

    public function blocksRequest(?string $token): bool
    {
        if (!$this->active()) {
            return false;
        }
        $bypass = $this->config->get('maintenance.bypass_token', null);
        if (is_string($bypass) && $bypass !== '' && is_string($token) && $token !== '') {
            return !hash_equals($bypass, $token);
        }
        return true;
    }

    public function enable(string $message = 'Технические работы, скоро вернёмся.', int $retryAfter = 3600): void
    {
        if ($retryAfter < 0 || $retryAfter > 86400) {
            throw new PortabilityException('Retry-After must be 0..86400 seconds.');
        }
        $state = [
            'active'      => true,
            'message'     => $message,
            'retry_after' => $retryAfter,
            'since'       => date('c'),
        ];
        $this->writeState($state);
        $this->cached = $state;
    }

    public function disable(): void
    {
        $state = [
            'active'      => false,
            'message'     => '',
            'retry_after' => 0,
            'since'       => null,
        ];
        $this->writeState($state);
        $this->cached = $state;
    }

    public function state(): array
    {
        return $this->read();
    }

    public function getHealthName(): string
    {
        return 'MaintenanceMode';
    }

    /**
     * Health: файл состояния читаем и это валидный JSON; активный режим —
     * WARNING (сайт живёт, но отвечает 503) — честно отражаем в отчёте Doctor.
     */
    public function healthCheck(): array
    {
        try {
            $raw = $this->storage->load(self::STATE_PATH);
            if ($raw !== null) {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded) || !array_key_exists('active', $decoded)) {
                    return $this->health(self::STATUS_FAIL, 'maintenance.json is corrupt.');
                }
            }
            // Проба записи (создать каталог нельзя — файл лежит в root storage; save сам создаёт).
            if ($raw === null && !$this->storage->save(self::STATE_PATH, json_encode($this->read()))) {
                return $this->health(self::STATUS_FAIL, 'Cannot write maintenance state.');
            }
            $state = $this->read();
            if ($state['active'] === true) {
                return $this->health(self::STATUS_WARNING, 'Maintenance mode is ACTIVE (site returns 503).');
            }
            return $this->health(self::STATUS_OK, 'ok');
        } catch (\Throwable $e) {
            return $this->health(self::STATUS_FAIL, 'Storage error: ' . $e->getMessage());
        }
    }

    /** @return array{active: bool, message: string, retry_after: int, since: ?string} */
    private function read(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $raw = $this->storage->load(self::STATE_PATH);
        $decoded = $raw === null ? null : json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->cached = ['active' => false, 'message' => '', 'retry_after' => 0, 'since' => null];
        }
        return $this->cached = [
            'active'      => ($decoded['active'] ?? false) === true,
            'message'     => (string) ($decoded['message'] ?? ''),
            'retry_after' => (int) ($decoded['retry_after'] ?? 3600),
            'since'       => isset($decoded['since']) ? (string) $decoded['since'] : null,
        ];
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || !$this->storage->save(self::STATE_PATH, $json)) {
            throw new PortabilityException('Cannot persist maintenance state.');
        }
    }

    /** @return array{name: string, status: string, message: string, context: array} */
    private function health(string $status, string $message, array $context = []): array
    {
        return ['name' => $this->getHealthName(), 'status' => $status, 'message' => $message, 'context' => $context];
    }
}
