<?php
declare(strict_types=1);

namespace Core\Module\Session;

/**
 * Контракт хранилища сессий (внутренний, не публикуется как сервис ядра).
 * Реализации: FileSessionStore (P1), RedisSessionStore (P1, opt-in).
 *
 * Миграция file+redis (принятое архитектурное решение):
 *  - primary читает первым; если данных нет, а secondary содержит запись —
 *    read-through миграция в primary (бесшовный переезд redis->file и
 *    file->redis без потери сессий пользователей);
 *  - при недоступности redis модуль деградирует до file автоматически (§7).
 */
interface SessionStoreInterface
{
    /** @return ?array<string, mixed> данные сессии или null (нет записи) */
    public function read(string $id): ?array;

    public function write(string $id, array $data): bool;

    public function destroy(string $id): void;

    /** Жив ли бэкенд прямо сейчас (для HealthCheck и деградации). */
    public function isHealthy(): bool;
}
