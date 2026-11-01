<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * FeatureFlag (P3, §8.4): точечное управление включением функциональности
 * без деплоя кода. Флаги хранятся в jailed-root FileStorage (флаг = JSON),
 * значения по умолчанию — в конфиге сайта `config/features.php`.
 */
interface FeatureFlagInterface
{
    /** Активен ли флаг (учитывает percentage-раскатку по стабильному key). */
    public function enabled(string $key, ?string $bucketKey = null): bool;

    /**
     * Текущее значение флага (raw) или $default, если флаг не задан.
     */
    public function value(string $key, mixed $default = null): mixed;

    /** Включить/выключить флаг (persist в storage). */
    public function set(string $key, bool $enabled): void;

    /** Установить процент раскатки 0..100. */
    public function setPercentage(string $key, int $percentage): void;

    /** Снять флаг (удалить override — вернётся default из конфига). */
    public function remove(string $key): void;

    /**
     * Все флаги с разрешёнными состояниями: key => array{name, enabled, percentage, source}.
     *
     * @return array<string, array>
     */
    public function all(): array;
}
