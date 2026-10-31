<?php
declare(strict_types=1);

namespace Law\Detector;

use Law\Contract\DetectorInterface;
use Law\ValueObject\Violation;

/**
 * Детектор C-009: Проверяет наличие declare(strict_types=1); в начале файла.
 */
final class StrictTypesDetector implements DetectorInterface
{
    public function getRuleId(): string
    {
        return 'C-009';
    }

    public function check(string $filePath, string $content, array $ruleConfig): ?Violation
    {
        // Игнорируем пустые файлы или файлы без PHP-тега
        if (!str_contains($content, '<?php')) {
            return null;
        }

        $tokens = token_get_all($content);
        $declareFound = false;

        // Проходим по токенам, ищем T_DECLARE
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_DECLARE) {
                $declareFound = true;
                
                // Берем следующие 10 токенов, чтобы проверить содержимое declare(...)
                $slice = array_slice($tokens, $index, 10);
                $snippet = '';
                foreach ($slice as $t) {
                    $snippet .= is_array($t) ? $t[1] : $t;
                }

                // Проверяем, что внутри именно strict_types=1
                if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/', $snippet)) {
                    // Нарушений нет, правило выполнено
                    return null; 
                }
                
                // Если T_DECLARE есть, но strict_types=1 нет - это нарушение
                break;
            }
        }

        // Если мы здесь, значит T_DECLARE не найден или некорректен
        return new Violation(
            ruleId: $this->getRuleId(),
            filePath: $filePath,
            line: 1, // Упрощенно указываем начало файла
            message: $ruleConfig['message'] ?? 'Отсутствует declare(strict_types=1);',
            hint: $ruleConfig['hint'] ?? 'Добавьте declare(strict_types=1); сразу после <?php',
            severity: $ruleConfig['severity'] ?? 'FAIL'
        );
    }
}