<?php
declare(strict_types=1);

namespace Core\Module\Doctor;

use Core\Contract\CacheInterface;
use Core\Contract\ConfigInterface;
use Core\Contract\DatabaseInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\HealthCheckInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\QueueInterface;

/**
 * Health-отчётчики для модулей, которые НЕ реализуют HealthCheckInterface
 * сами (исторически — P0/P1 до появления §7.2). Doctor (§7.2) опирается
 * только на ПУБЛИЧНЫЕ контракты: никаких reflection и лазов внутрь.
 */
final class ContractHealthChecks
{
    /**
     * @return HealthCheckInterface[] проверки по доступным сервисам контейнера
     */
    public static function for(
        ?FileStorageInterface $storage,
        ?LoggerInterface $logger,
        ?ConfigInterface $config,
        ?CacheInterface $cache,
        ?DatabaseInterface $db,
        ?QueueInterface $queue,
    ): array {
        $checks = [];
        if ($storage !== null) {
            $checks[] = new StorageHealth($storage);
        }
        if ($logger !== null) {
            $checks[] = new LoggerHealth($logger);
        }
        if ($config !== null) {
            $checks[] = new ConfigHealth($config);
        }
        if ($cache !== null) {
            $checks[] = new CacheHealth($cache);
        }
        if ($db !== null) {
            $checks[] = new DatabaseHealth($db);
        }
        if ($queue !== null) {
            $checks[] = new QueueHealth($queue);
        }
        return $checks;
    }
}

abstract class AbstractHealth implements HealthCheckInterface
{
    /** @return array{name: string, status: string, message: string, context: array} */
    protected function health(string $status, string $message, array $context = []): array
    {
        return ['name' => $this->getHealthName(), 'status' => $status, 'message' => $message, 'context' => $context];
    }
}

/**
 * FileStorage: jailed-root существует и запись возможна (проба .health-probe).
 */
final class StorageHealth extends AbstractHealth
{
    public function __construct(private readonly FileStorageInterface $storage)
    {
    }

    public function getHealthName(): string
    {
        return 'FileStorage';
    }

    public function healthCheck(): array
    {
        try {
            $probe = '.health-probe-' . bin2hex(random_bytes(4));
            if (!$this->storage->save($probe, 'x')) {
                return $this->health(self::STATUS_FAIL, 'Jailed root is not writable.');
            }
            $this->storage->delete($probe);
            return $this->health(self::STATUS_OK, 'ok');
        } catch (\Throwable $e) {
            return $this->health(self::STATUS_FAIL, 'Storage error: ' . $e->getMessage());
        }
    }
}

/**
 * Logger: канал app принимает записи (ошибка диска = fail сайта-в-логах).
 */
final class LoggerHealth extends AbstractHealth
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function getHealthName(): string
    {
        return 'Logger';
    }

    public function healthCheck(): array
    {
        try {
            $this->logger->info('doctor.probe');
            return $this->health(self::STATUS_OK, 'ok');
        } catch (\Throwable $e) {
            return $this->health(self::STATUS_FAIL, 'Cannot write logs: ' . $e->getMessage());
        }
    }
}

/**
 * Config: обязателен хотя бы один раздел (пустой конфиг = сайт не поднят).
 */
final class ConfigHealth extends AbstractHealth
{
    public function __construct(private readonly ConfigInterface $config)
    {
    }

    public function getHealthName(): string
    {
        return 'Config';
    }

    public function healthCheck(): array
    {
        try {
            $core = $this->config->get('core', null);
            if (!is_array($core)) {
                return $this->health(self::STATUS_WARNING, 'Section "core" missing in site config.');
            }
            return $this->health(self::STATUS_OK, 'ok');
        } catch (\Throwable $e) {
            return $this->health(self::STATUS_FAIL, 'Config error: ' . $e->getMessage());
        }
    }
}

/**
 * Cache: запись/чтение/сброс ключа-пробы.
 */
final class CacheHealth extends AbstractHealth
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function getHealthName(): string
    {
        return 'Cache';
    }

    public function healthCheck(): array
    {
        try {
            $key = 'doctor_probe_' . bin2hex(random_bytes(4));
            $this->cache->set($key, 1, 30);
            if ($this->cache->get($key) !== 1) {
                return $this->health(self::STATUS_FAIL, 'Cache roundtrip failed.');
            }
            $this->cache->delete($key);
            return $this->health(self::STATUS_OK, 'ok');
        } catch (\Throwable $e) {
            return $this->health(self::STATUS_FAIL, 'Cache error: ' . $e->getMessage());
        }
    }
}

/**
 * Database: деградация без БД — WARNING, не FAIL (§6: сайт живёт без БД).
 */
final class DatabaseHealth extends AbstractHealth
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function getHealthName(): string
    {
        return 'Database';
    }

    public function healthCheck(): array
    {
        try {
            if (!$this->db->isAvailable()) {
                return $this->health(self::STATUS_WARNING, 'Database disabled/unreachable — site runs without it (§6).');
            }
            $this->db->run('SELECT 1');
            return $this->health(self::STATUS_OK, 'ok');
        } catch (\Throwable $e) {
            return $this->health(self::STATUS_FAIL, 'DB ping failed: ' . $e->getMessage());
        }
    }
}

/**
 * Queue: счётки в состоянии backlog — WARNING (воркер не крутится).
 */
final class QueueHealth extends AbstractHealth
{
    public function __construct(private readonly QueueInterface $queue)
    {
    }

    public function getHealthName(): string
    {
        return 'Queue';
    }

    public function healthCheck(): array
    {
        try {
            $pending = $this->queue->pendingCount();
            $failed = $this->queue->failedCount();
            if ($failed > 0) {
                return $this->health(self::STATUS_WARNING, "Jobs in failed list.", ['pending' => $pending, 'failed' => $failed]);
            }
            if ($pending > 50) {
                return $this->health(self::STATUS_WARNING, "Backlog growing.", ['pending' => $pending]);
            }
            return $this->health(self::STATUS_OK, 'ok', ['pending' => $pending]);
        } catch (\Throwable $e) {
            return $this->health(self::STATUS_FAIL, 'Queue error: ' . $e->getMessage());
        }
    }
}
