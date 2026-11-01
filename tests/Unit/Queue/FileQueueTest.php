<?php
declare(strict_types=1);

namespace Tests\Unit\Queue;

use Core\Exception\QueueException;
use Core\Module\Config\Config;
use Core\Module\FileStorage\FileStorage;
use Core\Module\Queue\FileQueue;
use PHPUnit\Framework\TestCase;

final class FileQueueTest extends TestCase
{
    private string $root;
    private FileQueue $queue;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/lex-queue-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o755, true);
        $storage = FileStorage::create($this->root);
        $config = new Config();
        $config->set('queue.dir', 'queue');
        $config->set('queue.visibility_timeout', 60);
        $this->queue = new FileQueue($storage, $config);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testPushReturnsIdAndCountsPending(): void
    {
        $id = $this->queue->push('email.send', ['to' => 'a@b.c']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        self::assertSame(1, $this->queue->pendingCount());
        self::assertFileExists($this->root . '/queue/pending/' . $id . '.json');
    }

    public function testRejectsInvalidJobName(): void
    {
        $this->expectException(QueueException::class);
        $this->queue->push('../../etc/passwd');
    }

    public function testDelayedTaskIsNotVisibleUntilDue(): void
    {
        $this->queue->push('later.job', [], 3600);
        self::assertSame(0, $this->queue->pendingCount(), 'delayed не должен попадать в pending');
        self::assertFileExists($this->root . '/queue/delayed');
    }

    public function testWorkExecutesRegisteredHandlerWithPayload(): void
    {
        $seen = null;
        $this->queue->registerHandler('greet', static function (array $payload, string $jobId) use (&$seen): void {
            $seen = [$payload, $jobId];
        });
        $id = $this->queue->push('greet', ['name' => 'LEX']);

        $done = $this->queue->work();
        self::assertSame(1, $done);
        self::assertSame(['name' => 'LEX'], $seen[0]);
        self::assertSame($id, $seen[1]);
        self::assertSame(0, $this->queue->pendingCount());
        self::assertSame(0, $this->queue->failedCount());
    }

    public function testUnregisteredJobGoesToRetryThenDeadLetter(): void
    {
        $this->queue->push('ghost.job', [], 0, 1); // maxAttempts = 1 → сразу dead-letter
        $this->queue->work();
        self::assertSame(1, $this->queue->failedCount());
        self::assertStringContainsString(
            'No handler registered',
            (string) file_get_contents((glob($this->root . '/queue/failed/*.json')[0] ?? '')),
        );
    }

    public function testFailingHandlerRetriesWithBackoff(): void
    {
        $attempts = 0;
        $this->queue->registerHandler('flaky', static function () use (&$attempts): void {
            $attempts++;
            throw new \RuntimeException('boom #' . $attempts);
        });
        $this->queue->push('flaky', [], 0, 3);

        $this->queue->work();          // попытка 1 → fail → delayed (backoff 2с)
        self::assertSame(1, $attempts);
        self::assertSame(0, $this->queue->failedCount(), 'ещё не dead-letter');
        self::assertFileExists(glob($this->root . '/queue/delayed/*.json')[0] ?: '');
    }

    public function testCrashedWorkerReservedTaskReclaimedAfterTimeout(): void
    {
        // Эмуляция краша: вручную перенесём pending-файл в reserved с древним reserved_at.
        $id = $this->queue->push('crashy');
        $raw = file_get_contents($this->root . '/queue/pending/' . $id . '.json');
        $task = json_decode((string) $raw, true);
        $task['reserved_at'] = time() - 999;
        mkdir($this->root . '/queue/reserved', 0o755, true);
        file_put_contents($this->root . '/queue/reserved/' . $id . '.json', json_encode($task));
        unlink($this->root . '/queue/pending/' . $id . '.json');

        $executed = false;
        $this->queue->registerHandler('crashy', static function () use (&$executed): void {
            $executed = true;
        });
        $this->queue->work();
        self::assertTrue($executed, 'зависшая задача должна вернуться из reserved в pending и выполниться');
    }

    public function testWorkRespectsLimit(): void
    {
        $this->queue->registerHandler('bulk', static function (): void {});
        for ($i = 0; $i < 5; $i++) {
            $this->queue->push('bulk');
        }
        self::assertSame(2, $this->queue->work(2));
        self::assertSame(3, $this->queue->pendingCount());
    }
}
