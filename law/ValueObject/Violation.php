<?php
declare(strict_types=1);

namespace Law\ValueObject;

/**
 * Объект-значение, описывающий нарушение Конституции.
 */
final class Violation
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $filePath,
        public readonly int $line,
        public readonly string $message,
        public readonly string $hint,
        public readonly string $severity // 'FAIL' или 'WARN'
    ) {}

    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'file' => $this->filePath,
            'line' => $this->line,
            'severity' => $this->severity,
            'message' => $this->message,
            'hint' => $this->hint,
        ];
    }
}