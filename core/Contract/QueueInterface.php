<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт очереди задач (P2, §16.2).
 *
 * Философия: файловая очередь в jailed-root хранилища сайта. Никаких
 * демонов и внешних брокеров — сайт живёт без Redis/RabbitMQ (§ «дегра-
 * дация»: Queue деградирует до синхронного выполнения, если очередь
 * отключена конфигом). Обработчики регистрируются по имени задачи.
 *
 * Идемпотность — ответственность бизнес-логики обработчика; движок
 * гарантирует доставку «at-least-once» (краш воркера → задача вернётся
 * в pending после timeout visibility).
 */
interface QueueInterface
{
    /**
     * Поставить задачу в очередь.
     *
     * @param string $job    Имя зарегистрированного обработчика.
     * @param array  $payload Данные задачи (JSON-сериализуемые).
     * @param int    $delay   Задержка в секундах (0 — немедленно).
     * @param int    $maxAttempts Максимум попыток выполнения.
     *
     * @return string Уникальный идентификатор задачи.
     */
    public function push(string $job, array $payload = [], int $delay = 0, int $maxAttempts = 3): string;

    /**
     * Выполнить не более $limit задач из pending (worker-цикл).
     *
     * @return int Количество успешно выполненных задач.
     */
    public function work(int $limit = 10): int;

    /**
     * Зарегистрировать обработчик задачи по имени.
     *
     * @param callable(array $payload, string $jobId): void $handler
     */
    public function registerHandler(string $job, callable $handler): void;

    /**
     * Количество задач в состоянии pending (видимых сейчас).
     */
    public function pendingCount(): int;

    /**
     * Количество проваленных задач (в тупике dead).
     */
    public function failedCount(): int;
}
