<?php
declare(strict_types=1);

namespace Core\Module\Migration;

use Core\Contract\DatabaseInterface;
use Core\Contract\MigrationInterface;

/**
 * Файловый runner миграций (P2). Каждая миграция — пара PHP-файлов в
 * директории сайта storage/migrations/:
 *   0001_create_users.up.php / 0001_create_users.down.php
 * Файлы возвращают callable(DatabaseInterface): void.
 *
 * Журнал применения — таблица lex_migrations (создаётся автоматически при
 * первом migrate()). Идемпотентность: перед применением проверяется журнал;
 * каждая up-миграция выполняется в транзакции (§7 атомарность).
 */
final class FileMigrationRunner implements MigrationInterface
{
    public const VERSION = '1.0.0';

    private const JOURNAL_TABLE = 'lex_migrations';
    private const FILENAME = '/^[A-Za-z0-9_]+\.up\.php$/';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $migrationsDir,
    ) {
    }

    public function migrate(): array
    {
        $this->ensureJournal();
        $applied = $this->appliedNames();
        $done = [];

        foreach ($this->migrationFiles() as $name => $path) {
            if (isset($applied[$name])) {
                continue;
            }
            $callable = $this->load($path);
            $this->db->transaction(function (DatabaseInterface $db) use ($callable): void {
                $callable($db);
            });
            $this->db->insert(self::JOURNAL_TABLE, [
                'name' => $name,
                'applied_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $done[] = $name;
        }

        return $done;
    }

    public function rollback(int $steps = 1): array
    {
        $this->ensureJournal();
        $rows = $this->db->run(
            'SELECT name FROM ' . self::JOURNAL_TABLE . ' ORDER BY id DESC LIMIT ' . max(1, $steps),
        ) ?? [];

        $reverted = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $downPath = $this->migrationsDir . '/' . preg_replace('/\.up\.php$/', '.down.php', $name);
            if (!is_file($downPath)) {
                throw new MigrationException("No down-file for migration '{$name}'.");
            }
            $callable = $this->load($downPath);
            $this->db->transaction(function (DatabaseInterface $db) use ($callable): void {
                $callable($db);
            });
            $this->db->delete(self::JOURNAL_TABLE, ['name' => $name]);
            $reverted[] = $name;
        }

        return $reverted;
    }

    public function status(): array
    {
        $this->ensureJournal();
        $applied = $this->appliedNames();
        $out = [];
        foreach ($this->migrationFiles() as $name => $path) {
            $out[] = ['name' => $name, 'applied' => isset($applied[$name])];
        }
        return $out;
    }

    public function pendingCount(): int
    {
        $this->ensureJournal();
        $applied = $this->appliedNames();
        $n = 0;
        foreach (array_keys($this->migrationFiles()) as $name) {
            if (!isset($applied[$name])) {
                $n++;
            }
        }
        return $n;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @return array<string, true> имя => применена */
    private function appliedNames(): array
    {
        $rows = $this->db->run('SELECT name FROM ' . self::JOURNAL_TABLE) ?? [];
        $set = [];
        foreach ($rows as $r) {
            $set[(string) $r['name']] = true;
        }
        return $set;
    }

    /**
     * Отсортированные по имени файлы миграций. Только строгие имена (.up.php),
     * без обхода директорий (§20 jailed-root логика).
     *
     * @return array<string, string> name => path
     */
    private function migrationFiles(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }
        $files = [];
        foreach (scandir($this->migrationsDir) ?: [] as $f) {
            if (preg_match(self::FILENAME, $f) === 1) {
                $files[$f] = $this->migrationsDir . '/' . $f;
            }
        }
        ksort($files);
        return $files;
    }

    private function load(string $path): callable
    {
        $callable = require $path;
        if (!is_callable($callable)) {
            throw new MigrationException(basename($path) . ' must return a callable.');
        }
        return $callable;
    }

    private function ensureJournal(): void
    {
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS ' . self::JOURNAL_TABLE . ' ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'name TEXT NOT NULL UNIQUE,'
            . 'applied_at TEXT NOT NULL)',
        );
    }
}
