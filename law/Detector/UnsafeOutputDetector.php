<?php
declare(strict_types=1);

namespace Law\Detector;

use Law\Contract\DetectorInterface;
use Law\ValueObject\Violation;

final class UnsafeOutputDetector implements DetectorInterface
{
    public function getRuleId(): string
    {
        return 'C-040';
    }

    public function check(string $filePath, string $content, array $ruleConfig): ?Violation
    {
        // Проверяем только .phtml файлы
        if (!str_ends_with($filePath, '.phtml')) {
            return null;
        }

        // Ищем <?= без e() или {{ }}
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            // Пропускаем строки с {{ }} (они безопасны)
            if (str_contains($line, '{{')) {
                continue;
            }

            // Ищем <?= без e()
            if (preg_match('/<\?=\s*(?!e\()/', $line)) {
                return new Violation(
                    ruleId: $this->getRuleId(),
                    filePath: $filePath,
                    line: $lineNum + 1,
                    message: 'Небезопасный вывод без e(): ' . trim($line),
                    hint: 'Используйте <?= e($var) ?> или {{ $var }}',
                    severity: 'FAIL'
                );
            }
        }

        return null;
    }
}