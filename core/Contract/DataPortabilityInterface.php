<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * DataPortability (P3, §8.4): экспорт/импорт данных сайта между движками.
 *
 * Конституция требует переносимости: конфигурация и данные должны выгружаться
 * в переносимый формат (JSON) и загружаться обратно без ручных правок.
 * Экспортируются ТОЛЬКО данные jailed-root сайта — абсолютные пути запрещены (§20).
 */
interface DataPortabilityInterface
{
    /**
     * Экспорт набора разделов ('config', 'flags', ...) в JSON-строку.
     * Раздел 'env' (секреты) экспорту НЕ подлежит — выбрасывается исключение.
     */
    public function export(array $sections = ['config']): string;

    /**
     * Импорт из JSON-строки с валидацией схемы. Возвращает список применённых разделов.
     * Опасные ключи (абсолютные пути, ..) отклоняются.
     *
     * @return string[] применённые разделы
     */
    public function import(string $json): array;

    /** Доступные для экспорта разделы. @return string[] */
    public function availableSections(): array;
}
