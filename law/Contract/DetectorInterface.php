<?php
declare(strict_types=1);

namespace Law\Contract;

use Law\ValueObject\Violation;

/**
 * Контракт для любого детектора нарушений.
 */
interface DetectorInterface
{
    /**
     * Возвращает ID правила, которое проверяет этот детектор (например, 'C-009')
     */
    public function getRuleId(): string;

    /**
     * Проверяет файл на нарушение.
     * @return Violation|null Возвращает нарушение, если оно найдено, иначе null.
     */
    public function check(string $filePath, string $content, array $ruleConfig): ?Violation;
}