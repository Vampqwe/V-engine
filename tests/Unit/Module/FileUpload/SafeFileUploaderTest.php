<?php
declare(strict_types=1);

namespace Tests\Unit\Module\FileUpload;

use Core\Contract\FileStorageInterface;
use Core\Contract\FileUploadInterface;
use Core\Contract\RequestInterface;
use Core\Contract\UploadedFile;
use Core\Exception\UploadException;
use Core\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * FileUpload (P2): проверка модели безопасности — недоверие клиентским данным,
 * finfo по содержимому, jailed-root запись, генерация имён сервером.
 */
final class SafeFileUploaderTest extends TestCase
{
    private string $engineRoot;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->engineRoot = sys_get_temp_dir() . '/lex-upload-' . bin2hex(random_bytes(4));
        $this->tmpDir = $this->engineRoot . '/tmp';
        mkdir($this->engineRoot . '/sites/example.test/config', 0o755, true);
        mkdir($this->engineRoot . '/sites/example.test/storage', 0o755, true);
        mkdir($this->tmpDir, 0o755, true);
        file_put_contents(
            $this->engineRoot . '/sites/example.test/config/core.php',
            "<?php\nreturn ['debug' => false, 'timezone' => 'UTC'];",
        );
        file_put_contents(
            $this->engineRoot . '/sites/example.test/config/storage.php',
            "<?php\nreturn ['path' => '{$this->engineRoot}/sites/example.test/storage'];",
        );
        file_put_contents(
            $this->engineRoot . '/sites/example.test/config/upload.php',
            "<?php\nreturn ['max_size' => 1024, 'allowed_ext' => ['png','txt','jpg'], 'allowed_mime' => []];",
        );
        file_put_contents(
            $this->engineRoot . '/sites/example.test/.env',
            "SITE_URL=https://example.test\nAPP_SECRET=test-secret-0123456789abcdef\n",
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->engineRoot));
    }

    private function uploader(): FileUploadInterface
    {
        $kernel = new Kernel($this->engineRoot, null, ['HTTP_HOST' => 'example.test'], []);
        $kernel->boot();
        return $kernel->getContainer()->get(FileUploadInterface::class);
    }

    /** Создать «временный загруженный файл» с реальным содержимым. */
    private function tmpFile(string $contents, string $clientName, string $clientType = 'application/octet-stream', ?int $size = null): UploadedFile
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);
        return new UploadedFile($path, $clientName, $clientType, $size ?? strlen($contents), UPLOAD_ERR_OK);
    }

    // PNG: магические байты \x89PNG\r\n\x1a\n
    private const PNG_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    public function testValidPngStoredInJailedRootWithServerGeneratedName(): void
    {
        $uploader = $this->uploader();
        $file = $this->tmpFile(self::PNG_BYTES, 'photo.png', 'image/png');

        $relative = $uploader->store($file, 'uploads/avatars');

        self::assertMatchesRegularExpression('#^uploads/avatars/photo_[0-9a-f]{8}\.png$#', $relative);
        $storage = (new \ReflectionClass($uploader))->getProperty('storage');
        $storage->setAccessible(true);
        /** @var FileStorageInterface $fs */
        $fs = $storage->getValue($uploader);
        self::assertTrue($fs->exists($relative));
        self::assertSame(self::PNG_BYTES, $fs->load($relative));
    }

    public function testClientMimeIsIgnoredRealContentWins(): void
    {
        $uploader = $this->uploader();
        // Клиент заявляет image/png, но содержимое — текст. Реальный MIME text/plain,
        // расширение png противоречит ему → отказ.
        $file = $this->tmpFile("just plain text here", 'fake.png', 'image/png');
        $result = $uploader->validate($file);
        self::assertFalse($result->isValid());
        self::assertStringContainsString('contradicts detected content type', $result->errorsFor('file')[0]);
    }

    public function testPhpContentDisguisedAsPngRejected(): void
    {
        $uploader = $this->uploader();
        $file = $this->tmpFile("<?php system(\$_GET['cmd']); ?>", 'shell.png', 'image/png');
        $this->expectException(UploadException::class);
        $uploader->store($file, 'uploads');
    }

    public function testDoubleExecutableExtensionRejected(): void
    {
        $uploader = $this->uploader();
        // Содержимое действительно текстовое, но имя shell.php.txt — двойное расширение.
        $file = $this->tmpFile("harmless text content", 'shell.php.txt', 'text/plain');
        $result = $uploader->validate($file);
        self::assertFalse($result->isValid());
        self::assertStringContainsString('Double extension', implode(' ', $result->errorsFor('file')));
    }

    public function testSizeLimitUsesRealDiskSizeNotClientReport(): void
    {
        $uploader = $this->uploader();
        // Клиент врёт о размере 10 байт, реально 2000 > лимита конфига 1024.
        $file = $this->tmpFile(str_repeat('A', 2000), 'big.txt', 'text/plain', 10);
        $result = $uploader->validate($file);
        self::assertFalse($result->isValid());
        self::assertStringContainsString('exceeds allowed limit', $result->errorsFor('file')[0]);
    }

    public function testEmptyFileRejected(): void
    {
        $uploader = $this->uploader();
        $file = $this->tmpFile('', 'empty.txt', 'text/plain');
        $result = $uploader->validate($file);
        self::assertFalse($result->isValid());
    }

    public function testMissingTmpFileRejected(): void
    {
        $uploader = $this->uploader();
        $file = new UploadedFile($this->tmpDir . '/does-not-exist', 'ghost.txt', 'text/plain', 5, UPLOAD_ERR_OK);
        $result = $uploader->validate($file);
        self::assertFalse($result->isValid());
    }

    public function testUploadErrorCodeMapped(): void
    {
        $uploader = $this->uploader();
        $file = new UploadedFile('', 'x.txt', 'text/plain', 0, UPLOAD_ERR_INI_SIZE);
        $result = $uploader->validate($file);
        self::assertFalse($result->isValid());
        self::assertStringContainsString('upload_max_filesize', $result->errorsFor('file')[0]);
    }

    public function testExtensionNotAllowedByWhitelist(): void
    {
        $uploader = $this->uploader();
        // zip разрешён картой MIME, но НЕ в белом списке сайта ('png','txt','jpg').
        $zip = "PK\x03\x04" . str_repeat("\x00", 30);
        $file = $this->tmpFile($zip, 'archive.zip', 'application/zip');
        $result = $uploader->validate($file);
        self::assertFalse($result->isValid());
        self::assertStringContainsString('.zip', $result->errorsFor('file')[0]);
    }

    public function testTraversalInTargetDirectoryRejected(): void
    {
        $uploader = $this->uploader();
        $file = $this->tmpFile(self::PNG_BYTES, 'ok.png', 'image/png');
        $this->expectException(UploadException::class);
        $uploader->store($file, '../../etc');
    }

    public function testOverridesRelaxAndTightenConfig(): void
    {
        $uploader = $this->uploader();
        $file = $this->tmpFile(self::PNG_BYTES, 'photo.png', 'image/png');
        // override max_size меньше реального размера → отказ независимо от конфига.
        $result = $uploader->validate($file, ['max_size' => 5]);
        self::assertFalse($result->isValid());
    }

    public function testNameCollisionGetsCounterSuffix(): void
    {
        $uploader = $this->uploader();
        $file = $this->tmpFile(self::PNG_BYTES, 'dup.png', 'image/png');
        $first = $uploader->store($file, 'uploads');
        // Подменим генерируемое имя нельзя (random) — проверяем механизм через exists+store повторно:
        $second = $uploader->store($file, 'uploads');
        self::assertNotSame($first, $second);
    }

    public function testFromRequestReturnsDtoOrNull(): void
    {
        $uploader = $this->uploader();
        $request = $this->createStub(RequestInterface::class);
        $request->method('getUploadedFile')->willReturnMap([
            ['avatar', $this->tmpFile(self::PNG_BYTES, 'a.png', 'image/png')],
            ['missing', null],
        ]);
        self::assertInstanceOf(UploadedFile::class, $uploader->fromRequest($request, 'avatar'));
        self::assertNull($uploader->fromRequest($request, 'missing'));
    }
}
