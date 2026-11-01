#!/usr/bin/env php
<?php
declare(strict_types=1);

// ==============================================================================
// LEX CLI — консоль движка (очередь, планировщик). Статья 31 Конституции.
// Использование:
//   php bin/console.php queue:work [--site=demo.localhost] [--limit=10]
//   php bin/console.php queue:status [--site=...]
//   php bin/console.php scheduler:run [--site=...]
//   php bin/console.php --help
//
// Сайт выбирается аргументом --site (домен => папка sites/<домен>, §8 многосайтовость).
// В cron для планировщика: * * * * * php /path/bin/console.php scheduler:run --site=mysite.com
// ==============================================================================

if (!defined('ENGINE_BASE_PATH')) {
    define('ENGINE_BASE_PATH', dirname(__DIR__));
}

$autoloadPath = ENGINE_BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    fwrite(STDERR, "ERROR: vendor/autoload.php not found. Run: composer install\n");
    exit(2);
}
require_once $autoloadPath;

use Core\Contract\ConfigInterface;
use Core\Contract\QueueInterface;
use Core\Contract\SchedulerInterface;
use Core\Kernel;
use Core\Module\Config\Config;

// --- Разбор аргументов --------------------------------------------------------
$args = array_slice($argv, 1);
$command = null;
$site = null;
$limit = 10;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--site=')) {
        $site = substr($arg, 7);
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    } elseif ($arg === '--help' || $arg === '-h') {
        print_usage(0);
    } elseif ($command === null && !str_starts_with($arg, '-')) {
        $command = $arg;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        print_usage(2);
    }
}

if ($command === null) {
    print_usage(2);
}

// --- Загрузка окружения движка (корневой .env) --------------------------------
$engineEnv = [];
$rootEnvPath = ENGINE_BASE_PATH . '/.env';
if (is_file($rootEnvPath)) {
    try {
        $engineEnv = Config::parseEnvFile($rootEnvPath);
    } catch (\Throwable) {
        $engineEnv = [];
    }
}
foreach ($engineEnv as $key => $value) {
    if (is_string($key) && getenv($key) === false) {
        putenv($key . '=' . (string) $value);
    }
}

// --- Boot ядра ----------------------------------------------------------------
try {
    // Жёсткая привязка домен => папка сайта: флаг --site кладём в SITE_DOMAIN
    // (SiteContext::detect приоритет: SITE_URL > HTTP_HOST > SITE_DOMAIN).
    $env = $engineEnv;
    if ($site !== null && $site !== '') {
        $env['SITE_DOMAIN'] = $site;
    }
    $kernel = new Kernel(ENGINE_BASE_PATH, null, [], $env);
    $kernel->boot();
} catch (\Throwable $e) {
    $debug = filter_var(getenv('CORE_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    fwrite(STDERR, "BOOT ERROR: " . ($debug ? (string) $e : $e->getMessage()) . "\n");
    exit(1);
}

$exitCode = 0;
switch ($command) {
    case 'queue:work':
        $exitCode = runQueueWorker($kernel, $limit);
        break;
    case 'queue:status':
        $exitCode = showQueueStatus($kernel);
        break;
    case 'scheduler:run':
        $exitCode = runScheduler($kernel);
        break;
    case 'migrate':
    case 'migrate:status':
    case 'migrate:rollback':
        $exitCode = runMigration($kernel, $command, $args);
        break;
    default:
        fwrite(STDERR, "Unknown command: {$command}\n");
        print_usage(2);
}
exit($exitCode);

// ==============================================================================
// Реализации команд
// ==============================================================================

/**
 * queue:work — обрабатывает накопленные задачи. Обработчики регистрирует САЙТ
 * в своём bootstrap-файле sites/<домен>/bootstrap/queue_handlers.php
 * (возвращает callable(Kernel): void). Без файла — только разбор «пустых» задач.
 */
function runQueueWorker(Kernel $kernel, int $limit): int
{
    /** @var QueueInterface $queue */
    $queue = $kernel->get(QueueInterface::class);
    registerSiteQueueHandlers($kernel, $queue);

    $processed = $queue->work($limit);
    fwrite(STDOUT, sprintf("queue:work processed=%d pending=%d failed=%d\n", $processed, $queue->pendingCount(), $queue->failedCount()));
    return 0;
}

function showQueueStatus(Kernel $kernel): int
{
    /** @var QueueInterface $queue */
    $queue = $kernel->get(QueueInterface::class);
    fwrite(STDOUT, sprintf("pending=%d failed=%d\n", $queue->pendingCount(), $queue->failedCount()));
    return 0;
}

/**
 * scheduler:run — запускает задачи, наступившие на текущую минуту. Идемпотентен
 * (§ last-run метки внутри FileScheduler), безопасен для ежесекундного cron.
 */
function runScheduler(Kernel $kernel): int
{
    /** @var SchedulerInterface $scheduler */
    $scheduler = $kernel->get(SchedulerInterface::class);
    registerSiteScheduledJobs($kernel, $scheduler);

    $ran = $scheduler->run();
    foreach ($ran as $name => $outcome) {
        // $outcome: string|null — имя вытолкнутой job-задачи либо null (handler executed inline)
        fwrite(STDOUT, sprintf("scheduled: %s -> %s\n", (string) $name, is_string($outcome) ? 'queued ' . $outcome : 'ok'));
    }
    if ($ran === []) {
        fwrite(STDOUT, "scheduler:run nothing due\n");
    }
    return 0;
}

/** Загружает обработчики очередей сайта, если файл существует. */
function registerSiteQueueHandlers(Kernel $kernel, QueueInterface $queue): void
{
    /** @var ConfigInterface $config */
    $config = $kernel->get(ConfigInterface::class);
    $dir = (string) $config->get('engine.site_dir', '');
    $file = $dir !== '' ? rtrim($dir, '/') . '/bootstrap/queue_handlers.php' : '';
    if ($file !== '' && is_file($file)) {
        $registrar = require $file;
        if (is_callable($registrar)) {
            $registrar($kernel, $queue);
        }
    }
}

/** Загружает расписание сайта, если файл существует. */
function registerSiteScheduledJobs(Kernel $kernel, SchedulerInterface $scheduler): void
{
    /** @var ConfigInterface $config */
    $config = $kernel->get(ConfigInterface::class);
    $dir = (string) $config->get('engine.site_dir', '');
    $file = $dir !== '' ? rtrim($dir, '/') . '/bootstrap/schedule.php' : '';
    if ($file !== '' && is_file($file)) {
        $registrar = require $file;
        if (is_callable($registrar)) {
            $registrar($kernel, $scheduler);
        }
    }
}

/**
 * Команды миграций БД (P2). Файлы миграций сайта: sites/<домен>/storage/migrations/.
 * migrate / migrate:status / migrate:rollback --steps=N
 */
function runMigration(Kernel $kernel, string $command, array $args): int
{
    /** @var \Core\Contract\DatabaseInterface $db */
    $db = $kernel->get(\Core\Contract\DatabaseInterface::class);
    if (!$db->isAvailable()) {
        fwrite(STDERR, "Database not available for site (config/database.php enabled=false or no DSN).\n");
        return 1;
    }
    /** @var ConfigInterface $config */
    $config = $kernel->get(ConfigInterface::class);
    $dir = rtrim((string) $config->get('engine.site_dir', ''), '/') . '/storage/migrations';
    $runner = new \Core\Module\Migration\FileMigrationRunner($db, $dir);

    switch ($command) {
        case 'migrate':
            $applied = $runner->migrate();
            if ($applied === []) {
                fwrite(STDOUT, "migrate: nothing pending\n");
            } else {
                foreach ($applied as $name) {
                    fwrite(STDOUT, "migrated: {$name}\n");
                }
            }
            return 0;
        case 'migrate:status':
            foreach ($runner->status() as $row) {
                fwrite(STDOUT, sprintf("%-40s %s\n", $row['name'], $row['applied'] ? '[applied]' : '[pending]'));
            }
            fwrite(STDOUT, sprintf("pending=%d\n", $runner->pendingCount()));
            return 0;
        case 'migrate:rollback':
            $steps = 1;
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--steps=')) {
                    $steps = max(1, (int) substr($arg, 8));
                }
            }
            $reverted = $runner->rollback($steps);
            if ($reverted === []) {
                fwrite(STDOUT, "rollback: nothing to revert\n");
            } else {
                foreach ($reverted as $name) {
                    fwrite(STDOUT, "reverted: {$name}\n");
                }
            }
            return 0;
    }
    return 1;
}

function print_usage(int $code): never
{
    fwrite(STDOUT, <<<TXT
LEX Engine CLI

Commands:
  queue:work      Process queued jobs (--limit=N, default 10)
  queue:status    Show pending/failed job counts
  scheduler:run   Fire all jobs due at this minute (cron-safe, idempotent)

Options:
  --site=<domain> Site folder binding: sites/<domain> (default: cli.lex.local)
  --help          Show this help

Examples:
  php bin/console.php queue:work --site=demo.localhost --limit=50
  * * * * * php /path/to/engine/bin/console.php scheduler:run --site=mysite.com

TXT);
    exit($code);
}
