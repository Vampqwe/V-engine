<?php
declare(strict_types=1);

namespace Tests\Unit\Session;

use Core\Exception\FileStorageException;
use Core\Module\FileStorage\FileStorage;
use Core\Module\Session\FileSessionStore;
use Core\Module\Session\MigratingSessionStore;
use Core\Module\Session\NativeSession;
use Core\Module\Session\SessionStoreInterface;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sess-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function store(): FileSessionStore
    {
        return new FileSessionStore(FileStorage::create($this->dir), 'sess_');
    }

    public function testSetGetRoundtripPersistsAcrossInstances(): void
    {
        $session = new NativeSession($this->store());
        $sid = $session->id();
        $session->set('user_id', 42);
        $session->set('roles', ['admin']);

        // Новая «загрузка запроса» с тем же SID из cookie.
        $restored = new NativeSession($this->store(), $sid);
        self::assertSame(42, $restored->get('user_id'));
        self::assertSame(['admin'], $restored->get('roles'));
        self::assertTrue($restored->has('user_id'));
        self::assertNull($restored->get('missing'));
        self::assertSame('fallback', $restored->get('missing', 'fallback'));
    }

    public function testInvalidIncomingIdIsReplacedWithFreshOne(): void
    {
        foreach (['../../etc/passwd', 'not-hex!!', str_repeat('a', 31), ''] as $evil) {
            $session = new NativeSession($this->store(), $evil);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $session->id());
            self::assertFalse($session->hasIncomingId());
        }
    }

    public function testRemoveAndDestroy(): void
    {
        $store = $this->store();
        $session = new NativeSession($store);
        $sid = $session->id();
        $session->set('a', 1);
        $session->set('b', 2);
        $session->remove('a');
        self::assertFalse($session->has('a'));
        self::assertTrue($session->has('b'));

        $session->destroy();
        self::assertNull($store->read($sid));
    }

    public function testRegenerateKeepsDataButChangesId(): void
    {
        $store = $this->store();
        $session = new NativeSession($store);
        $oldId = $session->id();
        $session->set('priv', 'yes');

        $session->regenerate();
        $newId = $session->id();
        self::assertNotSame($oldId, $newId);
        self::assertSame('yes', $session->get('priv'));
        self::assertNull($store->read($oldId), 'старый сид обязан быть удалён');
        self::assertSame('yes', (new NativeSession($store, $newId))->get('priv'));
    }

    public function testFileStoreRejectsMalformedIds(): void
    {
        $store = $this->store();
        self::assertNull($store->read('../escape'));
        self::assertFalse($store->write('../escape', ['x' => 1]));
    }

    public function testUnserializeBlocksObjects(): void
    {
        $storage = FileStorage::create($this->dir);
        $sid = bin2hex(random_bytes(16));
        // Ручная запись payload с объектом — read обязан вернуть null-safe данные
        // без инстанцирования классов (allowed_classes=false).
        $storage->save('sess_' . $sid, serialize(['obj' => new \stdClass(), 'n' => 5]));
        $data = $this->store()->read($sid);
        self::assertIsArray($data);
        self::assertSame(5, $data['n']);
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $data['obj']);
    }

    // =========================================================================
    // Миграция file <-> redis (MigratingSessionStore)
    // =========================================================================

    public function testReadThroughMigratesLegacyToPrimary(): void
    {
        $legacy = new InMemoryStore();
        $primary = new InMemoryStore();
        $migrating = new MigratingSessionStore($primary, $legacy);

        $legacy->write('abc123', ['from' => 'legacy']);
        $data = $migrating->read('abc123');
        self::assertSame(['from' => 'legacy'], $data);
        // read-through: запись осеменила в primary.
        self::assertNotNull($primary->read('abc123'));
    }

    public function testDualWriteKeepsRollbackSafe(): void
    {
        $legacy = new InMemoryStore();
        $primary = new InMemoryStore();
        $migrating = new MigratingSessionStore($primary, $legacy);

        $migrating->write('id1', ['v' => 1]);
        self::assertNotNull($primary->read('id1'));
        self::assertNotNull($legacy->read('id1'), 'откат драйвера не должен терять сессии');

        $migrating->destroy('id1');
        self::assertNull($primary->read('id1'));
        self::assertNull($legacy->read('id1'));
    }

    public function testDegradationWhenPrimaryIsDown(): void
    {
        $legacy = new InMemoryStore();
        $primary = new InMemoryStore(healthy: false);
        $migrating = new MigratingSessionStore($primary, $legacy);

        $legacy->write('id9', ['survived' => true]);
        self::assertSame(['survived' => true], $migrating->read('id9'));
        self::assertTrue($migrating->write('id8', ['x' => 1]), 'file всё ещё пишет');
        self::assertNotNull($legacy->read('id8'));
        self::assertTrue($migrating->isHealthy());
    }
}

/** Тестовый in-memory store (эмуляция redis без ext-redis). */
final class InMemoryStore implements SessionStoreInterface
{
    /** @var array<string, array<string,mixed>> */
    private array $rows = [];

    public function __construct(private bool $healthy = true)
    {
    }

    public function read(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function write(string $id, array $data): bool
    {
        if (!$this->healthy) {
            return false;
        }
        $this->rows[$id] = $data;
        return true;
    }

    public function destroy(string $id): void
    {
        unset($this->rows[$id]);
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }
}