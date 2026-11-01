<?php
declare(strict_types=1);

namespace Law\Detector;

use Law\Contract\DetectorInterface;
use Law\ValueObject\Violation;

/**
 * Детектор C-040 (§40. Безопасный вывод в шаблонах): в .phtml-шаблонах сайтов
 * прямой вывод <?= допускается ТОЛЬКО через экранирование — e(...) или
 * шорткат шаблонов View-модуля $esc(...). Конструкция {{ $var }} безопасна
 * (компилируется View с автоэкранированием) и не проверяется посимвольно.
 */
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

        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            // Строки с {{ }} используют автоэкранирование View-модуля
            if (str_contains($line, '{{')) {
                continue;
            }

            // Извлекаем содержимое каждого вывода-тега на строке.
            // Открывающий и закрывающий теги собраны конкатенацией, чтобы сам
            // исходник детектора не содержал байтовой последовательности тега —
            // иначе PHP интерпретирует её как закрытие/открытие php-блока.
            $openTag  = '<' . '?=';
            $closeTag = '?' . '>';
            if (preg_match_all('/' . preg_quote($openTag, '/') . '(.*?)' . preg_quote($closeTag, '/') . '/', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $expr) {
                    $inner = trim($expr[1]);
                    // Безопасные формы: e(...) или $esc(...)
                    if (preg_match('/^(?:e|\$esc)\s*\(/', $inner) === 1) {
                        continue;
                    }
                    return new Violation(
                        ruleId: $this->getRuleId(),
                        filePath: $filePath,
                        line: $lineNum + 1,
                        message: 'Небезопасный вывод без экранирования: ' . trim($line),
                        hint: 'Используйте <?= $esc($var) ?> или <?= e($var) ?>',
                        severity: 'FAIL'
                    );
                }
            }
        }

        return null;
    }
}
