<?php
declare(strict_types=1);

namespace Core\Module\Database;

use Core\Contract\DatabaseInterface;
use Core\Exception\DatabaseNotConfiguredException;

/**
 * P1-модуль Database (§8.2.4, §7 graceful degradation).
 *
 * Инъекция PDO извне (через контейнер) — модуль не создаёт соединение сам,
 * это делает DatabaseModule на boot(). Все запросы — prepared statements
 * (§5). Имена таблиц/колонок проходят строгий whitelist-паттерн: SQL никогда
 * не собирается из пользовательских идентификаторов.
 *
 * Без конфигурации PDO не существует: любой CRUD бросает понятное
 * DatabaseNotConfiguredException ("Database not configured"), сайт живёт (§7).
 */
final class PdoDatabase implements DatabaseInterface
{
    public const VERSION = '1.0.0';

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(private readonly ?\PDO $pdo)
    {
    }

    // =========================================================================
    // DatabaseInterface
    // =========================================================================

    public function isAvailable(): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        try {
            return $this->pdo->query('SELECT 1') !== false;
        } catch (\PDOException) {
            return false;
        }
    }

    public function insert(string $table, array $data): int
    {
        $pdo = $this->pdo();
        if ($data === []) {
            throw new DatabaseNotConfiguredException('insert() requires at least one column.');
        }
        [$table, $colList] = $this->identifiers($table, array_keys($data));
        $placeholders = implode(', ', array_fill(0, count(array_keys($data)), '?'));

        $stmt = $pdo->prepare("INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})");
        $this->bindValues($stmt, array_values($data));
        $stmt->execute();

        return (int) $pdo->lastInsertId();
    }

    public function find(string $table, array $criteria): ?array
    {
        $rows = $this->limitFind($table, $criteria, 1);
        return $rows[0] ?? null;
    }

    public function findAll(string $table, array $criteria = []): array
    {
        return $this->limitFind($table, $criteria, null);
    }

    public function update(string $table, array $data, array $criteria): int
    {
        $pdo = $this->pdo();
        if ($data === []) {
            throw new DatabaseNotConfiguredException('update() requires at least one column.');
        }
        $columns = array_keys($data);
        [$table, $setCols] = $this->identifiers($table, $columns);
        $set = implode(', ', array_map(static fn ($c) => "{$c} = ?", $columns));
        [$where, $values] = $this->where($criteria, array_values($data));

        $stmt = $pdo->prepare("UPDATE {$table} SET {$set}{$where}");
        $this->bindValues($stmt, $values);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function delete(string $table, array $criteria): int
    {
        $pdo = $this->pdo();
        [$where, $values] = $this->where($criteria, []);
        // Пустой критерий = удаление всей таблицы — запрещено ядром явно.
        if ($where === '') {
            throw new DatabaseNotConfiguredException('delete() without criteria is forbidden.');
        }
        $stmt = $pdo->prepare("DELETE FROM {$this->identifier($table)}{$where}");
        $this->bindValues($stmt, $values);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ((bool) $pdo->inTransaction()) {
            // Вложенная транзакция: выполняем колбэк без нового BEGIN.
            return $callback($this);
        }
        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function run(string $sql, array $params = []): ?array
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare($sql);
        $this->bindValues($stmt, $params);
        $stmt->execute();

        // SELECT-подобные запросы возвращают строки; DDL/DML — null.
        return $stmt->columnCount() > 0 ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : null;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function pdo(): \PDO
    {
        if ($this->pdo === null) {
            throw new DatabaseNotConfiguredException(
                'Database not configured. Set DB_DSN/DB_USER/DB_PASS in site .env or run without persistence.',
            );
        }
        return $this->pdo;
    }

    /** @return array<int, string> */
    private function limitFind(string $table, array $criteria, ?int $limit): array
    {
        $pdo = $this->pdo();
        [$where, $values] = $this->where($criteria, []);
        $sql = 'SELECT * FROM ' . $this->identifier($table) . $where;
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        $stmt = $pdo->prepare($sql);
        $this->bindValues($stmt, $values);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * WHERE по критериям: значения — плейсхолдеры (?), ключи — идентификаторы.
     * NULL-критерий => IS NULL (плейсхолдер для NULL невозможен в LIMIT-форме).
     *
     * @param array<string, mixed> $criteria
     * @param list<mixed> $prefixValues значения SET, идущие первыми в UPDATE
     * @return array{0:string, 1:list<mixed>}
     */
    private function where(array $criteria, array $prefixValues): array
    {
        $parts = [];
        $values = $prefixValues;
        foreach ($criteria as $column => $value) {
            $col = $this->identifier((string) $column);
            if ($value === null) {
                $parts[] = "{$col} IS NULL";
                continue;
            }
            $parts[] = "{$col} = ?";
            $values[] = $value;
        }
        $where = $parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts);
        return [$where, $values];
    }

    /** Валидация и экранирование идентификатора (таблица/колонка). */
    private function identifier(string $name): string
    {
        if (!preg_match(self::IDENTIFIER, $name)) {
            throw new DatabaseNotConfiguredException(sprintf('Invalid SQL identifier "%s".', $name));
        }
        return '`' . $name . '`';
    }

    /**
     * @param list<string> $columns
     * @return array{0:string, 1:string} table, comma-joined quoted columns
     */
    private function identifiers(string $table, array $columns): array
    {
        $quoted = array_map(fn (string $c): string => $this->identifier($c), $columns);
        return [$this->identifier($table), implode(', ', $quoted)];
    }

    private function bindValues(\PDOStatement $stmt, array $values): void
    {
        foreach (array_values($values) as $i => $value) {
            $type = match (true) {
                is_int($value)  => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                $value === null => \PDO::PARAM_NULL,
                default         => \PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, $value, $type);
        }
    }
}
