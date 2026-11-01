<?php
declare(strict_types=1);

namespace Core\Module\Scheduler;

use Core\Contract\FileStorageInterface;
use Core\Contract\QueueInterface;
use Core\Contract\SchedulerInterface;

/**
 * Планировщик (P2). НЕ демон: cron раз в минуту вызывает bin/scheduler.php,
 * тот делает один проход run(). Состояние «последний запуск» — файл в
 * jailed-root, что исключает двойные запуски внутри одной минуты.
 *
 * Формат выражения: 5 полей "min hour day month weekday", поддерживаются
 * «*», числа, списки "a,b", диапазоны "a-b", шаги "star/n" и "a-b/n".
 */
final class FileScheduler implements SchedulerInterface
{
    public const VERSION = '1.0.0';

    /** @var array<string, array{cron:string, handler:?callable, job:?string}> */
    private array $tasks = [];

    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly ?QueueInterface $queue = null,
        private readonly string $stateDir = 'scheduler',
    ) {
    }

    public function every(string $name, string $cron, ?callable $handler = null, ?string $job = null): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.\-]{1,64}$/', $name)) {
            throw new \InvalidArgumentException('Invalid task name.');
        }
        if ($handler === null && $job === null) {
            throw new \InvalidArgumentException("Task '{$name}': need handler or queue job.");
        }
        // Валидация выражения сразу при регистрации — плохое крон-не «тихий» пропуск.
        $this->parseCron($cron);
        $this->tasks[$name] = ['cron' => $cron, 'handler' => $handler, 'job' => $job];
    }

    public function run(?int $now = null): array
    {
        $now ??= time();
        $minuteKey = intdiv($now, 60);
        $started = [];

        foreach ($this->tasks as $name => $task) {
            if (!$this->due($task['cron'], $now)) {
                continue;
            }
            if ($this->alreadyRan($name, $minuteKey)) {
                continue;
            }
            if ($task['handler'] !== null) {
                try {
                    ($task['handler'])();
                } catch (\Throwable) {
                    // Ошибка задачи не должна ломать проход остальных;
                    // фиксируем факт запуска, чтобы не крутить бесконечно.
                }
            } elseif ($this->queue !== null) {
                $this->queue->push((string) $task['job']);
            }
            $this->markRan($name, $minuteKey);
            $started[] = $name;
        }
        return $started;
    }

    public function due(string $cron, int $timestamp): bool
    {
        $f = $this->parseCron($cron);
        $min = (int) date('i', $timestamp);
        $hour = (int) date('G', $timestamp);
        $day = (int) date('j', $timestamp);
        $month = (int) date('n', $timestamp);
        $dow = (int) date('w', $timestamp);

        return self::matches($f[0], $min)
            && self::matches($f[1], $hour)
            && self::matches($f[2], $day)
            && self::matches($f[3], $month)
            && self::matches($f[4], $dow);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @return array<int, array<int,bool>|null> 5 наборов допустимых значений (null = «*»). */
    private function parseCron(string $cron): array
    {
        $parts = preg_split('/\s+/', trim($cron));
        if ($parts === false || count($parts) !== 5) {
            throw new \InvalidArgumentException("Invalid cron expression: '{$cron}'");
        }
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];
        $fields = [];
        foreach ($parts as $i => $part) {
            $fields[$i] = $this->parseField($part, $ranges[$i], $cron);
        }
        return $fields;
    }

    /** @param array{0:int,1:int} $range @return array<int,bool>|null */
    private function parseField(string $part, array $range, string $cron): ?array
    {
        if ($part === '*') {
            return null;
        }
        $set = [];
        foreach (explode(',', $part) as $item) {
            $step = 1;
            if (str_contains($item, '/')) {
                [$item, $stepStr] = explode('/', $item, 2);
                $step = (int) $stepStr;
                if ($step < 1) {
                    throw new \InvalidArgumentException("Bad step in cron '{$cron}'.");
                }
            }
            if ($item === '*') {
                $lo = $range[0];
                $hi = $range[1];
            } elseif (str_contains($item, '-')) {
                [$lo, $hi] = array_map(intval(...), explode('-', $item, 2));
            } else {
                $lo = $hi = (int) $item;
            }
            if ($lo < $range[0] || $hi > $range[1] || $lo > $hi) {
                throw new \InvalidArgumentException("Out-of-range field in cron '{$cron}'.");
            }
            for ($v = $lo; $v <= $hi; $v += $step) {
                $set[$v] = true;
            }
        }
        return $set;
    }

    /** @param array<int,bool>|null $set */
    private static function matches(?array $set, int $value): bool
    {
        return $set === null || isset($set[$value]);
    }

    private function statePath(string $name): string
    {
        return "{$this->stateDir}/{$name}.last";
    }

    private function alreadyRan(string $name, int $minuteKey): bool
    {
        $raw = $this->storage->load($this->statePath($name));
        return $raw !== null && (int) trim($raw) >= $minuteKey;
    }

    private function markRan(string $name, int $minuteKey): void
    {
        $this->storage->save($this->statePath($name), (string) $minuteKey);
    }
}
