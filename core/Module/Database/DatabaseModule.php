<?php
declare(strict_types=1);

namespace Core\Module\Database;

use Core\Contract\ConfigInterface;
use Core\Contract\DatabaseInterface;
use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\ModuleInterface;

/**
 * Модуль Database (P1, §8.2.4, §7). Без DB_DSN сайт живёт: публикуется
 * PdoDatabase(null), CRUD бросает понятное "Database not configured",
 * isAvailable() === false для HealthCheck и feature-фичек.
 */
final class DatabaseModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Database';
    }

    public function getVersion(): string
    {
        return PdoDatabase::VERSION;
    }

    public function getDependencies(): array
    {
        return [ConfigInterface::class];
    }

    public function getProvides(): array
    {
        return [DatabaseInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);

        $dsn = (string) ($config->get('db.dsn') ?? '');
        $user = (string) ($config->get('db.user') ?? '');
        $pass = (string) ($config->get('db.pass') ?? '');

        $pdo = null;
        if ($dsn !== '') {
            try {
                $pdo = new \PDO($dsn, $user, $pass, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false, // настоящие prepared statements (§5)
                ]);
            } catch (\PDOException $e) {
                // §7 graceful degradation: коннект упал — логируем и продолжаем без БД.
                $logger = $container->get(LoggerInterface::class);
                assert($logger instanceof LoggerInterface);
                $logger->channel('database')->error('db.connect_failed', ['error' => $e->getMessage()]);
                $pdo = null;
            }
        }

        $database = new PdoDatabase($pdo);
        $container->instance(DatabaseInterface::class, $database);
    }
}
