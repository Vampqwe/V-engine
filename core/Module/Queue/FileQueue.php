<?php
declare(strict_types=1);

namespace Core\Module\Queue;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\QueueInterface;
use Core\Exception\QueueException;

/**
 * Файловая очередь задач (P2). Одна задача = один JSON-файл в jailed-root.
 *
 * Структура хранилища (относительно root FileStorage сайта):
 *   queue/pending/{id}.json   — задача видима воркеру (available_at <= now)
 *   queue/delayed/{id}.json   — задержка ещё не истекла
 *   queue/reserved/{id}.json  — взята воркером (reserved_at + timeout)
 *   queue/failed/{id}.json    — исчерпаны попытки (dead-letter)
 *
 * Атомарность переходов — load+save+delete через FileStorage (jailed-root,
 * §20). Конкурентный захват защищён повторной проверкой существования
 * pending-файла после записи reserved-копии. Краш воркера → reserved-файл
 * возвращается в pending по истечении visibilityTimeout (at-least-once).
 */
final class FileQueue implements QueueInterface
{
    public const VERSION = '1.0.0';

    private string $dir;
    private int $visibilityTimeout;
    /** @var array<string, callable(array,string):void> */
    private array $handlers = [];

    public function __construct(
        private readonly FileStorageInterface $storage,
        ConfigInterface $config,
    ) {
        $this->dir = rtrim((string) ($config->get('queue.dir') ?? 'queue'), '/');
        $this->visibilityTimeout = max(10, (int) ($config->get('queue.visibility_timeout') ?? 60));
    }

    public function push(string $job, array $payload = [], int $delay = 0, int $maxAttempts = 3): string
    {
        if (!preg_match('/^[a-zA-Z0-9_.\-]{1,64}$/', $job)) {
            throw new QueueException('Invalid job name: ' . substr($job, 0, 64));
        }
        $id = bin2hex(random_bytes(8));
        $task = [
            'id' => $id,
            'job' => $job,
            'payload' => $payload,
            'attempts' => 0,
            'max_attempts' => max(1, $maxAttempts),
            'available_at' => time() + max(0, $delay),
            'created_at' => time(),
        ];
        $bucket = $delay > 0 ? 'delayed' : 'pending';
        $json = json_encode($task, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!$this->storage->save("{$this->dir}/{$bucket}/{$id}.json", $json)) {
            throw new QueueException('Failed to persist task.');
        }
        return $id;
    }

    public function registerHandler(string $job, callable $handler): void
    {
        $this->handlers[$job] = $handler;
    }

    public function work(int $limit = 10): int
    {
        $now = time();
        $this->promoteDelayed($now);
        $this->reclaimTimedOut($now);

        $done = 0;
        foreach ($this->listIds('pending') as $id) {
            if ($done >= $limit) {
                break;
            }
            if ($this->reserve($id) === null) {
                continue; // гонка с другим воркером — задача уже занята
            }
            if ($this->processReserved($id)) {
                $done++;
            }
        }
        return $done;
    }

    public function pendingCount(): int
    {
        return count($this->listIds('pending'));
    }

    public function failedCount(): int
    {
        return count($this->listIds('failed'));
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** Перевести просроченные delayed → pending. */
    private function promoteDelayed(int $now): void
    {
        foreach ($this->listIds('delayed') as $id) {
            $task = $this->loadTask('delayed', $id);
            if ($task !== null && $task['available_at'] <= $now) {
                $this->move($id, 'delayed', 'pending');
            }
        }
    }

    /** Вернуть зависшие reserved-задачи в pending по таймауту видимости. */
    private function reclaimTimedOut(int $now): void
    {
        foreach ($this->listIds('reserved') as $id) {
            $task = $this->loadTask('reserved', $id);
            if ($task === null) {
                continue;
            }
            if ($now - (int) ($task['reserved_at'] ?? 0) >= $this->visibilityTimeout) {
                $this->move($id, 'reserved', 'pending');
            }
        }
    }

    /** @return array|null Задание после успешного резервирования. */
    private function reserve(string $id): ?array
    {
        $task = $this->loadTask('pending', $id);
        if ($task === null || $task['available_at'] > time()) {
            return null;
        }
        $task['reserved_at'] = time();
        $json = json_encode($task, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!$this->storage->save("{$this->dir}/reserved/{$id}.json", $json)) {
            return null;
        }
        // Проверка гонок: если pending-файл исчез (другой воркер успел раньше) — отбой.
        if (!$this->storage->exists("{$this->dir}/pending/{$id}.json")) {
            $this->storage->delete("{$this->dir}/reserved/{$id}.json");
            return null;
        }
        $this->storage->delete("{$this->dir}/pending/{$id}.json");
        return $task;
    }

    /** Выполнить зарезервированную задачу. @return bool успех. */
    private function processReserved(string $id): bool
    {
        $task = $this->loadTask('reserved', $id);
        if ($task === null) {
            return false;
        }
        $task['attempts'] = (int) $task['attempts'] + 1;

        $handler = $this->handlers[$task['job']] ?? null;
        if ($handler === null) {
            $this->fail($task, "No handler registered for job '{$task['job']}'.");
            return false;
        }

        try {
            $handler($task['payload'], $task['id']);
        } catch (\Throwable $e) {
            $this->fail($task, $e->getMessage());
            return false;
        }

        $this->storage->delete("{$this->dir}/reserved/{$id}.json");
        return true;
    }

    /** Провал попытки: backoff → повторная постановка или dead-letter. */
    private function fail(array $task, string $error): void
    {
        $id = $task['id'];
        $this->storage->delete("{$this->dir}/reserved/{$id}.json");

        if ($task['attempts'] >= $task['max_attempts']) {
            $task['failed_at'] = time();
            $task['error'] = $error;
            $this->storage->save(
                "{$this->dir}/failed/{$id}.json",
                json_encode($task, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
            return;
        }

        // Экспоненциальный backoff: 2^attempt секунд (кап 5 минут).
        $task['available_at'] = time() + min(300, 2 ** (int) $task['attempts']);
        $this->storage->save(
            "{$this->dir}/delayed/{$id}.json",
            json_encode($task, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    private function move(string $id, string $from, string $to): bool
    {
        $content = $this->storage->load("{$this->dir}/{$from}/{$id}.json");
        if ($content === null) {
            return false;
        }
        if (!$this->storage->save("{$this->dir}/{$to}/{$id}.json", $content)) {
            return false;
        }
        $this->storage->delete("{$this->dir}/{$from}/{$id}.json");
        return true;
    }

    private function loadTask(string $bucket, string $id): ?array
    {
        $raw = $this->storage->load("{$this->dir}/{$bucket}/{$id}.json");
        if ($raw === null) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) && isset($data['id'], $data['job']) ? $data : null;
    }

    /** @return string[] id задач в бакете (сортировка stable по имени файла). */
    private function listIds(string $bucket): array
    {
        $files = $this->storage->list("{$this->dir}/{$bucket}/*.json");
        sort($files);
        $ids = [];
        foreach ($files as $f) {
            $ids[] = basename((string) $f, '.json');
        }
        return $ids;
    }
}
