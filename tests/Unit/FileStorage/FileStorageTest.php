<?php
declare(strict_types=1);

namespace Tests\Unit\FileStorage;

use Core\Exception\FileStorageException;
use Core\Module\FileStorage\FileStorage;
use PHPUnit\Framework\TestCase;

final class FileStorageTest extends TestCase
{
    private string $root;
    private FileStorage $fs;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/lex-fs-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o755, true);
        $this->fs = FileStorage::create($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testCreateRejectsMissingRoot(): void
    {
        $this->expectException(FileStorageException::class);
        FileStorage::create('/definitely/not/here/' . uniqid());
    }

    public function testSaveAndLoadRoundtrip(): void
    {
        self::assertTrue($this->fs->save('a/b/file.txt', 'hello'));
        self::assertSame('hello', $this->fs->load('a/b/file.txt'));
        self::assertTrue($this->fs->exists('a/b/file.txt'));
    }

    public function testLoadMissingReturnsNull(): void
    {
        self::assertNull($this->fs->load('nope.txt'));
    }

    public function testSaveCreatesDirectoriesRecursively(): void
    {
        self::assertTrue($this->fs->save('x/y/z/f.log', 'data'));
        self::assertDirectoryExists($this->root . '/x/y/z');
    }

    public function testDeleteFileAndDir(): void
    {
        $this->fs->save('del.txt', 'x');
        self::assertTrue($this->fs->delete('del.txt'));
        self::assertFalse($this->fs->exists('del.txt'));
        self::assertFalse($this->fs->delete('del.txt'));
    }

    public function testPathTraversalIsRejected(): void
    {
        $this->expectException(FileStorageException::class);
        $this->fs->save('../evil.txt', 'boom');
    }

    public function testAbsolutePathOutsideRootIsRejected(): void
    {
        $this->expectException(FileStorageException::class);
        $this->fs->load('/etc/passwd');
    }

    public function testSymlinkEscapeIsRejected(): void
    {
        $outside = sys_get_temp_dir() . '/lex-outside-' . bin2hex(random_bytes(4));
        mkdir($outside, 0o755, true);
        file_put_contents($outside . '/secret.txt', 'top-secret');
        symlink($outside, $this->root . '/link');

        // Запись через симлинг наружу должна быть отклонена.
        try {
            $this->fs->save('link/evil.txt', 'boom');
            self::fail('Escape via symlink was not detected.');
        } catch (FileStorageException) {
            self::assertFileDoesNotExist($outside . '/evil.txt');
        } finally {
            exec('rm -rf ' . escapeshellarg($outside));
        }
    }

    public function testListWithinJail(): void
    {
        $this->fs->save('logs/a.log', '1');
        $this->fs->save('logs/b.log', '2');
        $this->fs->save('logs/c.txt', '3');
        $found = $this->fs->list('logs/*.log');
        self::assertCount(2, $found);
        foreach ($found as $path) {
            self::assertStringStartsWith($this->fs->getRootDir(), $path);
        }
    }

    public function testAtomicWriteLeavesNoTmpFiles(): void
    {
        $this->fs->save('atomic.txt', 'v1');
        $this->fs->save('atomic.txt', 'v2');
        self::assertSame('v2', $this->fs->load('atomic.txt'));
        self::assertSame([], $this->fs->list('*.tmp'));
    }
}
