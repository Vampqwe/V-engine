<?php
declare(strict_types=1);

namespace Tests\Unit\Router;

use Core\Contract\UploadedFile;
use Core\Request;
use PHPUnit\Framework\TestCase;

/** Нормализация $_FILES → DTO в immutaбельном Request (FileUpload P2). */
final class RequestFilesTest extends TestCase
{
    public function testSingleFieldNormalizedToDto(): void
    {
        $request = Request::fromServer(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/upload'],
            [],
            [],
            ['avatar' => ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '/tmp/phpXX', 'error' => UPLOAD_ERR_OK, 'size' => 123]],
        );
        $file = $request->getUploadedFile('avatar');
        self::assertInstanceOf(UploadedFile::class, $file);
        self::assertSame('/tmp/phpXX', $file->tmpPath);
        self::assertSame('a.png', $file->name);
        self::assertSame(123, $file->size);
    }

    public function testMultiFieldExpandedToIndexedKeys(): void
    {
        $request = Request::fromServer(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/upload'],
            [],
            [],
            ['docs' => [
                'name'     => ['one.txt', '', 'three.txt'],
                'type'     => ['text/plain', '', 'text/plain'],
                'tmp_name' => ['/tmp/a', '', '/tmp/c'],
                'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK],
                'size'     => [10, 0, 30],
            ]],
        );
        $files = $request->getUploadedFiles();
        self::assertCount(2, $files, 'пустой слот формы отбрасывается');
        self::assertArrayHasKey('docs[0]', $files);
        self::assertArrayNotHasKey('docs[1]', $files);
        self::assertArrayHasKey('docs[2]', $files);
        self::assertSame('three.txt', $files['docs[2]']->name);
        self::assertNull($request->getUploadedFile('docs'));
    }

    public function testNoFilesIsEmptyMap(): void
    {
        $request = Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
        self::assertSame([], $request->getUploadedFiles());
        self::assertNull($request->getUploadedFile('x'));
    }

    public function testGarbageEntriesSkipped(): void
    {
        $request = Request::fromServer(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/'],
            [],
            [],
            ['bad' => 'not-an-array', 'ok' => ['name' => 'f.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/f', 'error' => UPLOAD_ERR_OK, 'size' => 5]],
        );
        self::assertNull($request->getUploadedFile('bad'));
        self::assertNotNull($request->getUploadedFile('ok'));
    }
}
