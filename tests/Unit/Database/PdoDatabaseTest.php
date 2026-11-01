<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Exception\DatabaseNotConfiguredException;
use Core\Module\Database\PdoDatabase;
use PHPUnit\Framework\TestCase;

final class PdoDatabaseTest extends TestCase
{
    private function sqlite(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:', options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, age INTEGER, active INTEGER)');
        return $pdo;
    }

    private function db(?\PDO $pdo): PdoDatabase
    {
        return new PdoDatabase($pdo);
    }

    // =========================================================================
    // Деградация (§7)
    // =========================================================================

    public function testUnavailableWithoutConfigAndThrowsClearError(): void
    {
        $db = $this->db(null);
        self::assertFalse($db->isAvailable());
        foreach ([
            'insert' => fn () => $db->insert('users', ['name' => 'x']),
            'find'   => fn () => $db->find('users', ['id' => 1]),
            'update' => fn () => $db->update('users', ['name' => 'y'], ['id' => 1]),
            'delete' => fn () => $db->delete('users', ['id' => 1]),
            'tx'     => fn () => $db->transaction(static fn () => null),
        ] as $op => $call) {
            try {
                $call();
                self::fail("{$op} must throw when DB is not configured");
            } catch (DatabaseNotConfiguredException $e) {
                self::assertStringContainsString('Database not configured', $e->getMessage());
            }
        }
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    public function testInsertFindUpdateDeleteRoundtrip(): void
    {
        $db = $this->db($this->sqlite());
        self::assertTrue($db->isAvailable());

        $id = $db->insert('users', ['name' => "O'Brien", 'age' => 30, 'active' => 1]);
        self::assertGreaterThan(0, $id);

        $row = $db->find('users', ['id' => $id]);
        self::assertIsArray($row);
        self::assertSame("O'Brien", $row['name'], 'prepared statements против инъекций');
        self::assertSame(30, (int) $row['age']);

        self::assertSame(1, $db->update('users', ['age' => 31], ['id' => $id]));
        self::assertSame(31, (int) $db->find('users', ['id' => $id])['age']);

        self::assertCount(1, $db->findAll('users'));
        self::assertSame(1, $db->delete('users', ['id' => $id]));
        self::assertNull($db->find('users', ['id' => $id]));
    }

    public function testSqlInjectionAttemptIsInert(): void
    {
        $db = $this->db($this->sqlite());
        $db->insert('users', ['name' => 'legit', 'age' => 1, 'active' => 1]);
        // Значение-атака — обычный параметр, не SQL.
        $victim = $db->find('users', ['name' => "legit'; DROP TABLE users; --"]);
        self::assertNull($victim);
        self::assertCount(1, $db->findAll('users'), 'таблица выжила');
    }

    public function testInvalidIdentifiersAreRejected(): void
    {
        $db = $this->db($this->sqlite());
        $this->expectException(DatabaseNotConfiguredException::class);
        $db->insert('users; DROP TABLE x', ['name' => 'a']);
    }

    public function testDeleteWithoutCriteriaIsForbidden(): void
    {
        $db = $this->db($this->sqlite());
        $this->expectException(DatabaseNotConfiguredException::class);
        $db->delete('users', []);
    }

    public function testNullCriteriaBecomesIsNull(): void
    {
        $db = $this->db($this->sqlite());
        $db->insert('users', ['name' => 'ghost', 'age' => null, 'active' => 1]);
        $found = $db->find('users', ['age' => null]);
        self::assertIsArray($found);
        self::assertSame('ghost', $found['name']);
    }

    // =========================================================================
    // Транзакции
    // =========================================================================

    public function testTransactionRollbackOnError(): void
    {
        $db = $this->db($this->sqlite());
        try {
            $db->transaction(function () use ($db): void {
                $db->insert('users', ['name' => 'tx1', 'age' => 1, 'active' => 1]);
                throw new \RuntimeException('boom');
            });
            self::fail('exception must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame([], $db->findAll('users', ['name' => 'tx1']));
    }

    public function testTransactionCommitOnSuccess(): void
    {
        $db = $this->db($this->sqlite());
        $result = $db->transaction(static function () use ($db): string {
            $db->insert('users', ['name' => 'tx2', 'age' => 2, 'active' => 1]);
            return 'ok';
        });
        self::assertSame('ok', $result);
        self::assertNotNull($db->find('users', ['name' => 'tx2']));
    }
}
