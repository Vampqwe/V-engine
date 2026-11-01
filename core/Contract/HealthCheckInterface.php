<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт самодиагностики модуля (§7.2 Конституции).
 *
 * Каждый модуль ядра ОТЧИТЫВАЕТСЯ сам о своём здоровье. Doctor только
 * агрегирует результаты — он НЕ подглядывает внутрь модулей и НЕ использует
 * reflection для «шпионажа» (§7.2, §7.3).
 *
 * Health-check обязан быть дешёвым и не бросать исключений: любое внутреннее
 * состояние (включая ошибку) возвращается как статус.
 */
interface HealthCheckInterface
{
    /** Модуль здоров и полностью функционален. */
    public const STATUS_OK = 'ok';

    /** Модуль работает в деградированном режиме (например, БД отключена — сайт живёт без неё, §6). */
    public const STATUS_WARNING = 'warning';

    /** Модуль неработоспособен (зависимость недоступна, запись невозможна и т.п.). */
    public const STATUS_FAIL = 'fail';

    /**
     * Имя модуля для отчёта (совпадает с Registry name).
     */
    public function getHealthName(): string;

    /**
     * Проверка здоровья. Реализация НЕ должна бросать исключения —
     * вместо этого возвращает self::STATUS_*.
     *
     * @return array{name: string, status: string, message: string, context: array}
     */
    public function healthCheck(): array;
}
