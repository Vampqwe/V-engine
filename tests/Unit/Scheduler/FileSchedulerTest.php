<?php
declare(strict_types=1);

namespace Tests\Unit\Scheduler;

use Core\Module\FileStorage\FileStorage;
use Core\Module\Scheduler\FileScheduler;
use PHPUnit\Framework\TestCase;

final class FileSchedulerTest extends TestCase
{
    private string $root;
    private FileScheduler $sched;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/lex-sched-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o755, true);
        $this->sched = new FileScheduler(FileStorage::create($this->root));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testDueMatchesEveryMinute(): void
    {
        self::assertTrue($this->sched->due('* * * * *', time()));
    }

    public function testDueStepSyntax(): void
    {
        // Шаг 15 по минутам: 0,15,30,45
        $t = strtotime('2026-01-01 10:30:00');
        self::assertTrue($this->sched->due('*/15 * * * *', (int) $t));
        $t2 = strtotime('2026-01-01 10:31:00');
        self::assertFalse($this->sched->due('*/15 * * * *', (int) $t2));
    }

    public function testDueListsRangesAndDayOfWeek(): void
    {
        $monday = strtotime('2026-01-05 09:00:00'); // Mon
        self::assertTrue($this->sched->due('0 9 * * 1', (int) $monday));
        self::assertFalse($this->sched->due('0 9 * * 2', (int) $monday), 'Mon не совпадает с Tue');
        self::assertTrue($this->sched->due('0,30 * * * *', (int) strtotime('2026-01-05 12:30:00')));
        self::assertTrue($this->sched->due('0 8-17 * * 1-5', (int) $monday));
    }

    public function testInvalidCronRejectedAtRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sched->every('bad', 'not a cron', static function (): void {});
    }

    public function testTaskWithoutHandlerOrJobRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sched->every('orphan', '* * * * *');
    }

    public function testRunExecutesDueTasksOncePerMinute(): void
    {
        $count = 0;
        $this->sched->every('tick', '* * * * *', static function () use (&$count): void {
            $count++;
        });
        $now = time();
        $started = $this->sched->run($now);
        self::assertSame(['tick'], $started);
        self::assertSame(1, $count);

        // Повторный проход в ту же минуту — защита от двойного запуска.
        $again = $this->sched->run($now + 5);
        self::assertSame([], $again);
        self::assertSame(1, $count);
    }

    public function testRunSkipsNotDueTasks(): void
    {
        $fired = false;
        $this->sched->every('midnight', '0 0 * * *', static function () use (&$fired): void {
            $fired = true;
        });
        $started = $this->sched->run(strtotime('2026-01-01 12:00:00'));
        self::assertSame([], $started);
        self::assertFalse($fired);
    }

    public function testFailingTaskDoesNotBreakPass(): void
    {
        $secondRan = false;
        $this->sched->every('boom', '* * * * *', static function (): void {
            throw new \RuntimeException('fail');
        });
        $this->sched->every('ok', '* * * * *', static function () use (&$secondRan): void {
            $secondRan = true;
        });
        $started = $this->sched->run(time());
        self::assertSame(['boom', 'ok'], $started);
        self::assertTrue($secondRan);
    }
}
