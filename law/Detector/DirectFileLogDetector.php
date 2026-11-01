<?php
declare(strict_types=1);

namespace Law\Detector;

use Law\Contract\DetectorInterface;
use Law\ValueObject\Violation;

/**
 * Детектор C-031 (ст. 33. Изолированные каналы логирования): пользовательское
 * пространство (sites/<domain>/module/) не имеет права писать логи в обход
 * LoggerInterface — запрещены прямые вызовы file_put_contents/fopen/fwrite.
 *
 * Реализация на токенах: ловим вызовы функций-писарей (имя + '('), игнорируя
 * объявления функций с такими же именами.
 */
final class DirectFileLogDetector implements DetectorInterface
{
    /** Функции прямой записи, используемые для «самописных» логов. */
    private const WRITE_FUNCTIONS = ['file_put_contents', 'fopen', 'fwrite'];

    public function getRuleId(): string
    {
        return 'C-031';
    }

    public function check(string $filePath, string $content, array $ruleConfig): ?Violation
    {
        if (!$this->isUserSpace($filePath)) {
            return null; // ядро и конфиги пишут логи сами — это их работа
        }

        $tokens = token_get_all($content);
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            $name = strtolower($token[1]);
            if (!in_array($name, self::WRITE_FUNCTIONS, true)) {
                continue;
            }
            // Это вызов функции, а не объявление/константа? Смотрим следующий нетабличный токен: '('
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;
            }
            if ($j >= $count || $tokens[$j] !== '(') {
                continue;
            }
            // Объявление функции (`function fopen(...)`) — пропускаем: перед именем идёт T_STRING? нет, T_FUNCTION.
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_WHITESPACE], true)) {
                $prev--;
            }
            if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_FUNCTION) {
                continue;
            }

            return new Violation(
                ruleId: $this->getRuleId(),
                filePath: $filePath,
                line: $token[2],
                message: ($ruleConfig['message'] ?? 'Прямая запись в файл вместо LoggerInterface') . ": {$token[1]}()",
                hint: $ruleConfig['hint'] ?? 'Используйте LoggerInterface::channel("name")',
                severity: $ruleConfig['severity'] ?? 'FAIL',
            );
        }

        return null;
    }

    private function isUserSpace(string $filePath): bool
    {
        $normalized = str_replace('\\', '/', $filePath);
        // sites/{домен}/module/... — пользовательские модули сайтов
        return (bool) preg_match('#/(sites|public)/[^/]+/(module|bootstrap)/#', $normalized);
    }
}
