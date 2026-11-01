<?php
declare(strict_types=1);

namespace Tests\Unit\Migration;

use Core\Contract\DatabaseInterface;
use Core\Exception\DatabaseNotConfiguredException;
use Core\Module\Database\PdoDatabase;
use Core\Module\Migration\FileMigrationRunner;
use Core\Module\Migration\MigrationException;
use PHPUnit\Framework\TestCase;

final class FileMigrationRunnerTest extends TestCase
{
    private string $dir;
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Требуется расширение pdo_sqlite для тестов миграций.');
        }
        $pdo = new \PDO('sqlite::memory:', options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->db = new PdoDatabase($pdo);
        $this->dir = sys_get_temp_dir() . '/lex_mig_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function write(string $name, string $body): void
    {
        // use-импорт: замыкания в require'd-файле резолвят несQUALIFIED имена
        // относительно namespace файла (global), а не Core\Contract.
        file_put_contents(
            $this->dir . '/' . $name,
            "<?php\nuse Core\\Contract\\DatabaseInterface;\nreturn {$body};\n",
        );
    }

    public function testMigrateAppliesInOrderAndIsIdempotent(): void
    {
        $this->write('0001_a.up.php', "static function (DatabaseInterface \$db): void { \$db->run('CREATE TABLE t1 (id INTEGER PRIMARY KEY)'); }");
        $this->write('0002_b.up.php', "static function (DatabaseInterface \$db): void { \$db->run('CREATE TABLE t2 (id INTEGER PRIMARY KEY)'); }");
        $runner = new FileMigrationRunner($this->db, $this->dir);

        self::assertSame(['0001_a.up.php', '0002_b.up.php'], $runner->migrate());
        // Повторный вызов — ничего не применяем (идемпотентность).
        self::assertSame([], $runner->migrate());
        self::assertSame(0, $runner->pendingCount());

        // Таблицы реально созданы и журнал заполнен.
        self::assertNotNull($this->db->run('SELECT * FROM t1'));
        $rows = $this->db->run('SELECT name FROM lex_migrations ORDER BY id');
        self::assertCount(2, $rows);
    }

    public function testRollbackRevertsLastApplied(): void
    {
        $this->write('0001_a.up.php', "static function (DatabaseInterface \$db): void { \$db->run('CREATE TABLE t1 (id INTEGER PRIMARY KEY)'); }");
        $this->write('0001_a.down.php', "static function (DatabaseInterface \$db): void { \$db->run('DROP TABLE t1'); }");
        $runner = new FileMigrationRunner($this->db, $this->dir);

        $runner->migrate();
        self::assertSame(['0001_a.up.php'], $runner->rollback(1));
        self::assertSame(1, $runner->pendingCount());
        // down-скрипт реально выполнился: таблицы нет.
        $tables = $this->db->run("SELECT name FROM sqlite_master WHERE type='table' AND name='t1'");
        self::assertSame([], $tables);
    }

    public function testRollbackWithoutDownFileThrows(): void
    {
        $this->write('0001_a.up.php', "static function (DatabaseInterface \$db): void { \$db->run('CREATE TABLE t1 (id INTEGER PRIMARY KEY)'); }");
        $runner = new FileMigrationRunner($this->db, $this->dir);
        $runner->migrate();

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('No down-file');
        $runner->rollback(1);
    }

    public function testStatusReportsAppliedAndPending(): void
    {
        $this->write('0001_a.up.php', "static function (DatabaseInterface \$db): void { \$db->run('CREATE TABLE t1 (id INTEGER PRIMARY KEY)'); }");
        $this->write('0002_b.up.php', "static function (DatabaseInterface \$db): void { \$db->run('CREATE TABLE t2 (id INTEGER PRIMARY KEY)'); }");
        $this->write('0002_b.down.php', "static function (DatabaseInterface \$db): void { \$db->run('DROP TABLE t2'); }");
        $runner = new FileMigrationRunner($this->db, $this->dir);

        $runner->migrate();
        $runner->rollback(1); // откатим только последнюю -> mixed статус

        self::assertSame(
            [['name' => '0001_a.up.php', 'applied' => true], ['name' => '0002_b.up.php', 'applied' => false]],
            $runner->status(),
        );
        self::assertSame(1, $runner->pendingCount());
    }

    public function testNonCallableMigrationFileThrows(): void
    {
        $this->write('0001_bad.up.php', "'not-a-callable'");
        $runner = new FileMigrationRunner($this->db, $this->dir);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('must return a callable');
        $runner->migrate();
    }

    public function testJunkFilesAreIgnored(): void
    {
        $this->write('notes.txt', "static function (): void {}");
        $this->write('evil.php', "static function (): void {}");
        $this->write('0001_ok.up.php', "static function (DatabaseInterface \$db): void { \$db->run('CREATE TABLE ok1 (id INTEGER PRIMARY KEY)'); }");
        $runner = new FileMigrationRunner($this->db, $this->dir);

        self::assertSame(['0001_ok.up.php'], $runner->migrate());
    }

    public function testFailedMigrationRollsBackTransactionAndStaysPending(): void
    {
        $this->write('0001_boom.up.php', "static function (DatabaseInterface \$db): void { \$db->run('CREATE TABLE half (id INTEGER PRIMARY KEY)'); throw new \\RuntimeException('boom'); }");
        $runner = new FileMigrationRunner($this->db, $this->dir);

        try {
            $runner->migrate();
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        // Транзакция откачена: таблицы half нет…
        $tables = $this->db->run("SELECT name FROM sqlite_master WHERE type='table' AND name='half'");
        self::assertSame([], $tables);
        // …и миграция осталась pending (не записана в журнал).
        self::assertSame(1, $runner->pendingCount());
    }

    public function testMissingMigrationsDirectoryIsEmptyRun(): void
    {
        $runner = new FileMigrationRunner($this->db, $this->dir . '/nope');
        self::assertSame([], $runner->migrate());
        self::assertSame(0, $runner->pendingCount());
    }

    public function testUnavailableDatabasePropagatesClearError(): void
    {
        $runner = new FileMigrationRunner(new PdoDatabase(null), $this->dir);
        $this->expectException(DatabaseNotConfiguredException::class);
        $runner->migrate();
    }
}
