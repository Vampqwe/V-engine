<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * MaintenanceMode (P3, §8.4): режим технических работ для сайта.
 * Состояние — один файл в jailed-root; решение принимает middleware
 * до диспатча маршрута. Админ может проходить по секретному токену.
 */
interface MaintenanceModeInterface
{
    /** Активен ли режим техработ. */
    public function active(): bool;

    /**
     * Активен ли режим ДЛЯ ДАННОГО запроса (учитывает bypass-токен).
     */
    public function blocksRequest(?string $token): bool;

    /**
     * Войти в режим. $retryAfter — секунды для заголовка Retry-After (503).
     */
    public function enable(string $message = 'Технические работы, скоро вернёмся.', int $retryAfter = 3600): void;

    /** Выйти из режима. */
    public function disable(): void;

    /**
     * Текущее состояние: array{active: bool, message: string, retry_after: int, since: ?string}.
     *
     * @return array
     */
    public function state(): array;
}
