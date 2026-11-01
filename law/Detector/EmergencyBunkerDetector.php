<?php
declare(strict_types=1);

namespace Law\Detector;

use Law\Contract\DetectorInterface;
use Law\ValueObject\Violation;

/**
 * Детектор C-001 (§29. Аварийный люк): public/index.php обязан содержать
 * register_shutdown_function и константу ENGINE_BASE_PATH — иначе фатальная
 * ошибка PHP останется без безопасного fallback-ответа.
 */
final class EmergencyBunkerDetector implements DetectorInterface
{
    public function getRuleId(): string
    {
        return 'C-001';
    }

    public function check(string $filePath, string $content, array $ruleConfig): ?Violation
    {
        // Правило точечное: только корневая точка входа.
        $normalized = str_replace('\\', '/', $filePath);
        if (!str_ends_with($normalized, '/public/index.php')) {
            return null;
        }

        $hasShutdown  = str_contains($content, 'register_shutdown_function');
        $hasBaseConst = str_contains($content, "define('ENGINE_BASE_PATH'")
            || str_contains($content, 'define("ENGINE_BASE_PATH"');

        if ($hasShutdown && $hasBaseConst) {
            return null;
        }

        $missing = [];
        if (!$hasShutdown) {
            $missing[] = 'register_shutdown_function()';
        }
        if (!$hasBaseConst) {
            $missing[] = 'define(\'ENGINE_BASE_PATH\', ...)';
        }

        return new Violation(
            ruleId: $this->getRuleId(),
            filePath: $filePath,
            line: 1,
            message: ($ruleConfig['message'] ?? 'Аварийный люк отсутствует') . ' Не найдено: ' . implode(', ', $missing),
            hint: $ruleConfig['hint'] ?? 'Добавьте аварийный обработчик в начало public/index.php',
            severity: $ruleConfig['severity'] ?? 'FAIL',
        );
    }

}
