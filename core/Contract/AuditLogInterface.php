<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт журнала аудита (P2, §16.2).
 *
 * Пишет структурированные события «кто, что, когда, из какого IP».
 * Хранилище: БД при наличии DatabaseInterface->isConnected(), иначе —
 * деградация в файл-канал Logger 'audit' (jailed-root, атомарная запись).
 * записи неизменяемы (append-only): движок не предоставляет update/delete.
 */
interface AuditLogInterface
{
    /**
     * Зафиксировать событие аудита.
     *
     * @param string   $action  Имя действия ('user.login', 'order.create').
     * @param array    $context Произвольные данные (JSON-сериализуемые).
     * @param int|null $userId  Идентификатор субъекта (null = гость/система).
     */
    public function log(string $action, array $context = [], ?int $userId = null): void;

    /**
     * Вычитать последние $limit записей (для Doctor/админки).
     *
     * @return array[] Массив записей ['ts'=>int,'action'=>string,'user'=>?int,'ip'=>?string,'context'=>array].
     */
    public function recent(int $limit = 50): array;
}
