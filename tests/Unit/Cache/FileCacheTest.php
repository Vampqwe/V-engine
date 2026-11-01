<?php
declare(strict_types=1);

namespace Tests\Unit\Cache;

use Core\Exception\EngineException;
use Core\Module\Cache\FileCache;
use Core\Module\FileStorage\FileStorage;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $dir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cache-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
        $this->cache = new FileCache(FileStorage::create($this->dir), 'cache');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testSetGetRoundtripWithComplexTypes(): void
    {
        $value = ['list' => [1, 2, 3], 'nested' => ['ok' => true]];
        self::assertTrue($this->cache->set('cfg', $value));
        self::assertSame($value, $this->cache->get('cfg'));
        self::assertTrue($this->cache->has('cfg'));
    }

    public function testMissReturnsDefault(): void
    {
        self::assertNull($this->cache->get('absent'));
        self::assertSame('d', $this->cache->get('absent', 'd'));
        self::assertFalse($this->cache->has('absent'));
    }

    public function testFalseValueIsDistinguishableFromMiss(): void
    {
        $this->cache->set('flag', false);
        self::assertFalse($this->cache->get('flag', 'DEFAULT'));
        self::assertTrue($this->cache->has('flag'));
    }

    public function testTtlExpiry(): void
    {
        $this->cache->set('short', 'v', 1);
        self::assertSame('v', $this->cache->get('short'));
        // Имитируем истечение: переписываем expire в прошлое напрямую через storage.
        $storage = FileStorage::create($this->dir);
        $storage->save('cache/short.cache', (time() - 10) . "\n" . serialize('v'));
        self::assertNull($this->cache->get('short'), 'просроченный ключ — miss');
        self::assertFalse($storage->exists('cache/short.cache'), 'просроченный файл обязан быть удалён');
    }

    public function testDeleteAndClear(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        self::assertTrue($this->cache->delete('a'));
        self::assertFalse($this->cache->has('a'));
        self::assertTrue($this->cache->has('b'));

        self::assertTrue($this->cache->clear());
        self::assertFalse($this->cache->has('b'));
    }

    public function testPathTraversalKeysAreRejected(): void
    {
        $this->expectException(EngineException::class);
        $this->cache->get('../../etc/passwd');
    }

    public function testInvalidKeyOnSetReturnsFalse(): void
    {
        self::assertFalse($this->cache->set('bad key with space!', 1));
        self::assertFalse($this->cache->set(str_repeat('k', 129), 1));
    }

    public function testSerializedObjectsAreNotInstantiated(): void
    {
        $storage = FileStorage::create($this->dir);
        $storage->save('cache/evil.cache', "0\n" . serialize(['o' => new \stdClass()]));
        $data = $this->cache->get('evil');
        self::assertIsArray($data);
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $data['o']);
    }
}
