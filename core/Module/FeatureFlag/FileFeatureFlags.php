<?php
declare(strict_types=1);

namespace Core\Module\FeatureFlag;

use Core\Contract\ConfigInterface;
use Core\Contract\FeatureFlagInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\HealthCheckInterface;
use Core\Exception\PortabilityException;

/**
 * FeatureFlag (P3, §8.4). Хранение: один JSON-флаг = один файл в jailed-root
 * (`flags/{key}.json`), значения по умолчанию — секция конфига `features`.
 *
 * Разрешение флага (priority): override в storage > default из конфига.
 * Percentage-раскатка детерминирована: crc32(key|bucket) % 100 < percent —
 * один и тот же пользователь стабильно получает одно и то же значение.
 */
final class FileFeatureFlags implements FeatureFlagInterface, HealthCheckInterface
{
    public const VERSION = '1.0.0';

    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /** @var array<string, ?array> кэш прочитанных overrides за время жизни экземпляра */
    private array $overrides = [];

    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly ConfigInterface $config,
    ) {
    }

    public function enabled(string $key, ?string $bucketKey = null): bool
    {
        $flag = $this->resolve($key);
        if (!$flag['enabled']) {
            return false;
        }
        $percent = $flag['percentage'];
        if ($percent >= 100) {
            return true;
        }
        if ($percent <= 0) {
            return false;
        }
        // Стабильная бакет-функция: без bucketKey — равномерный раскат на запрос.
        $bucket = $bucketKey !== null ? $bucketKey : bin2hex(random_bytes(4));
        return (crc32($key . '|' . $bucket) % 100) < $percent;
    }

    public function value(string $key, mixed $default = null): mixed
    {
        $override = $this->readOverride($key);
        if ($override !== null && array_key_exists('value', $override)) {
            return $override['value'];
        }
        $defaults = $this->defaults();
        if (isset($defaults[$key]) && is_array($defaults[$key]) && array_key_exists('value', $defaults[$key])) {
            return $defaults[$key]['value'];
        }
        return $default;
    }

    public function set(string $key, bool $enabled): void
    {
        $this->assertKey($key);
        $current = $this->readOverride($key) ?? [];
        $current['enabled'] = $enabled;
        unset($current['percentage']);
        $this->writeOverride($key, $current);
    }

    public function setPercentage(string $key, int $percentage): void
    {
        $this->assertKey($key);
        if ($percentage < 0 || $percentage > 100) {
            throw new PortabilityException('Percentage must be 0..100.');
        }
        $current = $this->readOverride($key) ?? [];
        $current['enabled'] = true;
        $current['percentage'] = $percentage;
        $this->writeOverride($key, $current);
    }

    public function remove(string $key): void
    {
        $this->assertKey($key);
        $this->storage->delete('flags/' . $key . '.json');
        unset($this->overrides[$key]);
    }

    public function all(): array
    {
        $result = [];
        foreach ($this->defaults() as $key => $def) {
            $result[(string) $key] = $this->resolve((string) $key) + ['source' => 'config'];
        }
        foreach ($this->storage->list('flags/*.json') as $path) {
            $key = basename((string) $path, '.json');
            $result[$key] = $this->resolve($key) + ['source' => 'storage'];
        }
        return $result;
    }

    public function getHealthName(): string
    {
        return 'FeatureFlag';
    }

    /**
     * Health: jailed-root доступен для записей флагов (§7.2 — модуль сам
     * отчитывается; никаких исключений наружу).
     */
    public function healthCheck(): array
    {
        try {
            if (!$this->storage->createDir('flags')) {
                return $this->health(self::STATUS_FAIL, 'Storage directory "flags" is not writable.');
            }
            $probe = 'flags/.health-probe';
            if (!$this->storage->save($probe, '{}')) {
                return $this->health(self::STATUS_FAIL, 'Cannot write feature flags to storage.');
            }
            $this->storage->delete($probe);
            return $this->health(self::STATUS_OK, 'ok', ['flags' => count($this->all())]);
        } catch (\Throwable $e) {
            return $this->health(self::STATUS_FAIL, 'Storage error: ' . $e->getMessage());
        }
    }

    /** @return array{name: string, status: string, message: string, context: array} */
    private function health(string $status, string $message, array $context = []): array
    {
        return ['name' => $this->getHealthName(), 'status' => $status, 'message' => $message, 'context' => $context];
    }

    /**
     * @return array{enabled: bool, percentage: int}
     */
    private function resolve(string $key): array
    {
        $this->assertKey($key);
        $override = $this->readOverride($key);
        if ($override !== null) {
            return [
                'enabled'    => (bool) ($override['enabled'] ?? false),
                'percentage' => (int) ($override['percentage'] ?? 100),
            ];
        }
        $def = $this->defaults()[$key] ?? null;
        if (is_array($def)) {
            return [
                'enabled'    => (bool) ($def['enabled'] ?? false),
                'percentage' => (int) ($def['percentage'] ?? 100),
            ];
        }
        return ['enabled' => (bool) $def, 'percentage' => 100];
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        $section = $this->config->get('features', []);
        return is_array($section) ? $section : [];
    }

    /** @return ?array<string, mixed> */
    private function readOverride(string $key): ?array
    {
        if (array_key_exists($key, $this->overrides)) {
            return $this->overrides[$key];
        }
        $raw = $this->storage->load('flags/' . $key . '.json');
        if ($raw === null) {
            return $this->overrides[$key] = null;
        }
        $decoded = json_decode($raw, true);
        return $this->overrides[$key] = is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $data */
    private function writeOverride(string $key, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || !$this->storage->save('flags/' . $key . '.json', $json)) {
            throw new PortabilityException('Cannot persist feature flag "' . $key . '".');
        }
        $this->overrides[$key] = $data;
    }

    private function assertKey(string $key): void
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new PortabilityException('Invalid feature flag key: ' . $key);
        }
    }
}
