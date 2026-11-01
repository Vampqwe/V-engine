<?php
declare(strict_types=1);

namespace Tests\Unit\Logger;

use Core\Module\FileStorage\FileStorage;
use Core\Module\Logger\FileLogger;
use PHPUnit\Framework\TestCase;

final class FileLoggerTest extends TestCase
{
    private string $root;
    private FileStorage $fs;
    private FileLogger $logger;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/lex-log-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o755, true);
        $this->fs = FileStorage::create($this->root);
        $this->logger = new FileLogger($this->fs);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array<int, array<string, mixed>> декодированные JSONL-записи канала */
    private function readChannel(string $channel = 'app'): array
    {
        $files = $this->fs->list('logs/' . $channel . '-*.log');
        $records = [];
        foreach ($files as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                if ($line !== '') {
                    $records[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                }
            }
        }
        return $records;
    }

    public function testInfoWritesJsonLineWithLevelAndChannel(): void
    {
        $this->logger->info('boot ok', ['pid' => 42]);
        $records = $this->readChannel();
        self::assertCount(1, $records);
        self::assertSame('INFO', $records[0]['level']);
        self::assertSame('app', $records[0]['channel']);
        self::assertSame('boot ok', $records[0]['message']);
        self::assertSame(['pid' => 42], $records[0]['context']);
        self::assertArrayHasKey('ts', $records[0]);
    }

    public function testAppendsToSameDailyFile(): void
    {
        $this->logger->info('one');
        $this->logger->error('two');
        $records = $this->readChannel();
        self::assertCount(2, $records);
        self::assertSame('ERROR', $records[1]['level']);
    }

    public function testChannelProducesSeparateFiles(): void
    {
        $security = $this->logger->channel('security');
        $security->warning('intrusion');
        $this->logger->info('routine');

        self::assertCount(1, $this->readChannel('security'));
        self::assertCount(1, $this->readChannel('app'));
    }

    public function testThrowableContextIsSanitized(): void
    {
        $this->logger->critical('failed', ['e' => new \RuntimeException('kaput', 7)]);
        $records = $this->readChannel();
        self::assertSame('RuntimeException', $records[0]['context']['e']['class']);
        self::assertSame('kaput', $records[0]['context']['e']['message']);
    }

    public function testDateTimeContextBecomesIso(): void
    {
        $dt = new \DateTimeImmutable('2026-11-01T12:00:00+00:00');
        $this->logger->info('when', ['t' => $dt]);
        $records = $this->readChannel();
        self::assertSame('2026-11-01T12:00:00+00:00', $records[0]['context']['t']);
    }

    public function testEmptyChannelFallsBackToApp(): void
    {
        $this->logger->channel('  ')->info('x');
        self::assertSame('app', $this->readChannel()[0]['channel']);
    }

    public function testHostileChannelNameCannotEscapeJail(): void
    {
        $this->logger->channel('../../evil')->info('x');
        // Канал санифицирован в имя файла (слэши => '_'): файл остаётся внутри jail.
        $found = $this->fs->list('logs/*');
        self::assertNotEmpty($found);
        foreach ($found as $path) {
            self::assertStringStartsWith($this->fs->getRootDir(), $path);
        }
        self::assertFileExists($this->fs->getRootDir() . '/logs/.._.._evil-' . date('Y-m-d') . '.log');
    }
}
