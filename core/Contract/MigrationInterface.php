<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт системы миграций (P2, §16.2).
 * Идемпотентный runner: повторный apply() не выполняет уже применённые миграции.
 */
interface MigrationInterface
{
    /**
     * Применить все ещё не выполненные миграции (по возрастанию имени файла).
     * @return list<string> имена применённых миграций
     */
    public function migrate(): array;

    /** Откатить N последних применённых миграций (нужен down-файл). */
    public function rollback(int $steps = 1): array;

    /** Статус: какие миграции применены, какие ожидают. */
    public function status(): array;

    /** Имена файлов в директории миграций (валидированные). */
    public function pendingCount(): int;
}
