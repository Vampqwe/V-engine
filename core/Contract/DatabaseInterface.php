<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт БД (P1, §16.1). Только prepared statements внутри (§5 защита
 * от SQL-инъекций). Деградация: без конфига — понятное исключение (§7).
 */
interface DatabaseInterface
{
    public function insert(string $table, array $data): int;

    public function find(string $table, array $criteria): ?array;

    public function findAll(string $table, array $criteria = []): array;

    public function update(string $table, array $data, array $criteria): int;

    public function delete(string $table, array $criteria): int;

    public function transaction(callable $callback): mixed;

    /** Активна ли БД (конфиг + коннект). Для HealthCheck и graceful degradation. */
    public function isAvailable(): bool;
}
