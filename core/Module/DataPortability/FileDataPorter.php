<?php
declare(strict_types=1);

namespace Core\Module\DataPortability;

use Core\Contract\ConfigInterface;
use Core\Contract\DataPortabilityInterface;
use Core\Contract\FeatureFlagInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\HealthCheckInterface;
use Core\Exception\PortabilityException;

/**
 * DataPortability (P3, §8.4): переносимость данных сайта между движками.
 *
 * Экспорт — только разрешённые разделы в один JSON-пакет со схемой:
 *   { "format": "lex-portability", "version": 1, "site": {...}, "sections": {...} }
 *
 * Безопасность (§20, §31):
 *  - абсолютные пути и traversal-сегменты отклоняются на импорте (рекурсивно);
 *  - секреты (.env, ключ APP_SECRET, bypass_token) НЕ экспортируются никогда —
 *    фильтр по маскам ключей применяется к конфигу до сериализации;
 *  - импорт применяет разделы атомарно пофайлово: невалидный пакет не пишется вообще.
 */
final class FileDataPorter implements DataPortabilityInterface, HealthCheckInterface
{
    public const VERSION = '1.0.0';

    private const FORMAT = 'lex-portability';
    private const SCHEMA_VERSION = 1;

    /** Ключи конфига, подлежащие вырезанию из экспорта (секреты, привязки окружения). */
    private const SECRET_KEY_PATTERN = '/(secret|password|passwd|token|api_key|dsn|trusted_proxies)/i';

    /** Разделы, запрещённые к экспорту целиком. */
    private const FORBIDDEN_SECTIONS = ['env'];

    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly ConfigInterface $config,
        private readonly ?FeatureFlagInterface $flags = null,
    ) {
    }

    public function availableSections(): array
    {
        $sections = ['config', 'flags'];
        if ($this->storage->exists('maintenance.json')) {
            $sections[] = 'maintenance';
        }
        return $sections;
    }

    public function export(array $sections = ['config']): string
    {
        foreach ($sections as $section) {
            if (in_array($section, self::FORBIDDEN_SECTIONS, true)) {
                throw new PortabilityException("Section '{$section}' contains secrets and cannot be exported.");
            }
            if (!in_array($section, $this->availableSections(), true)) {
                throw new PortabilityException("Unknown export section: {$section}");
            }
        }

        $payload = [];
        foreach (array_unique($sections) as $section) {
            $payload[$section] = match ($section) {
                'config'      => $this->sanitizeConfig($this->config->all()),
                'flags'       => $this->exportFlags(),
                'maintenance' => $this->exportMaintenance(),
                default       => throw new PortabilityException("Unsupported section: {$section}"),
            };
        }

        $packet = [
            'format'  => self::FORMAT,
            'version' => self::SCHEMA_VERSION,
            'exported_at' => date('c'),
            'sections' => $payload,
        ];

        $json = json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $json;
    }

    public function import(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new PortabilityException('Import payload is not valid JSON.');
        }
        if (($decoded['format'] ?? null) !== self::FORMAT || ($decoded['version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new PortabilityException('Unsupported portability packet format/version.');
        }
        $sections = $decoded['sections'] ?? null;
        if (!is_array($sections) || $sections === []) {
            throw new PortabilityException('Packet has no sections.');
        }
        foreach (array_keys($sections) as $name) {
            if (!is_string($name) || !in_array($name, $this->availableSections(), true)) {
                throw new PortabilityException('Packet contains unknown section: ' . (is_string($name) ? $name : '?'));
            }
        }

        // Полная валидация ДО записи: ни один опасный путь не должен коснуться диска.
        foreach ($sections as $name => $data) {
            if (!is_array($data)) {
                throw new PortabilityException("Section '{$name}' must be an object.");
            }
            $this->assertSafePaths($data, $name);
        }

        $applied = [];
        foreach ($sections as $name => $data) {
            match ($name) {
                'config'      => $this->importConfig($data),
                'flags'       => $this->importFlags($data),
                'maintenance' => $this->importMaintenance($data),
            };
            $applied[] = $name;
        }
        return $applied;
    }

    public function getHealthName(): string
    {
        return 'DataPortability';
    }

    public function healthCheck(): array
    {
        try {
            // Проба: экспорт конфигов в памяти без записи на диск.
            $this->export(['config']);
            return $this->health(self::STATUS_OK, 'ok');
        } catch (\Throwable $e) {
            return $this->health(self::STATUS_FAIL, 'Export probe failed: ' . $e->getMessage());
        }
    }

    private function health(string $status, string $message, array $context = []): array
    {
        return ['name' => $this->getHealthName(), 'status' => $status, 'message' => $message, 'context' => $context];
    }

    // ── config ────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $config */
    private function sanitizeConfig(array $config): array
    {
        $clean = [];
        foreach ($config as $key => $value) {
            if (preg_match(self::SECRET_KEY_PATTERN, (string) $key) === 1) {
                continue; // секрет или привязка окружения — не экспортируем
            }
            $clean[$key] = is_array($value) ? $this->sanitizeConfig($value) : $value;
        }
        return $clean;
    }

    /**
     * Импорт конфигурации: значения применяются к рантайму (Config::set) —
     * файлов site-config на диск НЕ пишем (конфиги сайта живут в git, §20 jail
     * относится к данным; перезапись PHP-секций была бы обходом ревью).
     * @param array<string,mixed> $data
     */
    private function importConfig(array $data): void
    {
        foreach ($data as $key => $value) {
            if (preg_match(self::SECRET_KEY_PATTERN, (string) $key) === 1) {
                throw new PortabilityException('Config key looks like a secret and cannot be imported: ' . $key);
            }
            $this->config->set((string) $key, $value);
        }
    }

    // ── flags ─────────────────────────────────────────────────────────────

    private function exportFlags(): array
    {
        if ($this->flags === null) {
            throw new PortabilityException('FeatureFlag module is not available for flags section.');
        }
        return $this->flags->all();
    }

    /** @param array<string,mixed> $data */
    private function importFlags(array $data): void
    {
        if ($this->flags === null) {
            throw new PortabilityException('FeatureFlag module is not available for flags section.');
        }
        foreach ($data as $key => $spec) {
            $key = (string) $key;
            if (is_bool($spec)) {
                $this->flags->set($key, $spec);
                continue;
            }
            if (is_array($spec) && array_key_exists('enabled', $spec)) {
                $this->flags->set($key, (bool) $spec['enabled']);
                if (isset($spec['percentage']) && is_int($spec['percentage'])) {
                    $this->flags->setPercentage($key, $spec['percentage']);
                }
                continue;
            }
            throw new PortabilityException("Invalid flag definition for '{$key}'.");
        }
    }

    // ── maintenance ───────────────────────────────────────────────────────

    private function exportMaintenance(): array
    {
        $raw = $this->storage->load('maintenance.json');
        if ($raw === null) {
            return ['active' => false];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new PortabilityException('maintenance.json is corrupt — fix before export.');
        }
        unset($decoded['bypass_token']); // на всякий случай: токен живёт в конфиге, не здесь
        return $decoded;
    }

    /** @param array<string,mixed> $data */
    private function importMaintenance(array $data): void
    {
        if (!array_key_exists('active', $data) || !is_bool($data['active'])) {
            throw new PortabilityException("Maintenance section requires boolean 'active'.");
        }
        if (!$this->storage->save('maintenance.json', json_encode($data, JSON_THROW_ON_ERROR))) {
            throw new PortabilityException('Cannot write maintenance state.');
        }
    }

    // ── path safety ───────────────────────────────────────────────────────

    /**
     * Рекурсивно отклоняем абсолютные пути и traversal во всех строковых
     * значениях, выглядящих как пути (ключ *_path/*dir, значения с разделителями).
     * @param array<array-key,mixed> $data
     */
    private function assertSafePaths(array $data, string $where): void
    {
        foreach ($data as $key => $value) {
            $looksLikePath = is_string($key)
                && (str_ends_with($key, '_path') || str_ends_with($key, '_dir') || $key === 'path' || $key === 'dir');
            if (is_array($value)) {
                $this->assertSafePaths($value, $where . '.' . $key);
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            if ($looksLikePath && ($value !== '' && ($value[0] === '/' || $value[0] === '\\' || preg_match('/^[a-zA-Z]:[\\\\\/]/', $value) === 1))) {
                throw new PortabilityException("Absolute path in '{$where}.{$key}' is forbidden (§20).");
            }
            if (str_contains($value, '..' . DIRECTORY_SEPARATOR) || str_contains($value, '../') || str_contains($value, '..\\')) {
                throw new PortabilityException("Traversal segment in '{$where}.{$key}' is forbidden (§20).");
            }
        }
    }
}
