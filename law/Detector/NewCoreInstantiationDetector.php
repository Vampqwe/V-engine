<?php
declare(strict_types=1);

namespace Law\Detector;

use Law\Contract\DetectorInterface;
use Law\ValueObject\Violation;

/**
 * Детектор C-011: Запрещает прямое создание объектов ядра (new \Core\...)
 * в пользовательском пространстве (public/{...}/module/).
 */
final class NewCoreInstantiationDetector implements DetectorInterface
{
    public function getRuleId(): string
    {
        return 'C-011';
    }

    public function check(string $filePath, string $content, array $ruleConfig): ?Violation
    {
        // Проверяем, находится ли файл в пользовательском пространстве
        if (!$this->isUserSpace($filePath)) {
            return null; // В ядре (core/) или других системных папках это допустимо
        }

        $tokens = token_get_all($content);
        $tokenCount = count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            // Ищем T_NEW
            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            // Пропускаем пробелы после 'new'
            $j = $i + 1;
            while ($j < $tokenCount && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            if ($j >= $tokenCount) {
                continue;
            }

            // Проверяем, начинается ли имя класса с \Core\
            $className = $this->extractClassName($tokens, $j);

            if ($className !== null && str_starts_with($className, '\\Core\\')) {
                $line = is_array($tokens[$i]) ? $tokens[$i][2] : 1;

                return new Violation(
                    ruleId: $this->getRuleId(),
                    filePath: $filePath,
                    line: $line,
                    message: $ruleConfig['message'] ?? "Обнаружено прямое создание объекта ядра: new {$className}",
                    hint: $ruleConfig['hint'] ?? 'Используйте $container->get(Interface::class) вместо прямого создания',
                    severity: $ruleConfig['severity'] ?? 'FAIL'
                );
            }
        }

        return null;
    }

    /**
     * Проверяет, находится ли файл в пользовательском пространстве.
     * Пользовательское пространство: public/{site}/module/
     */
    private function isUserSpace(string $filePath): bool
    {
        // Нормализуем пути для кроссплатформенности
        $normalizedPath = str_replace('\\', '/', $filePath);
        
        // Паттерн: public/любая_папка/module/
        return (bool) preg_match('#/public/[^/]+/module/#', $normalizedPath);
    }

    /**
     * Извлекает полное имя класса из токенов, начиная с позиции $startIndex.
     * Возвращает строку вида "\Core\Database\PdoDriver" или null, если не удалось извлечь.
     */
    private function extractClassName(array $tokens, int $startIndex): ?string
    {
        $className = '';
        $tokenCount = count($tokens);

        for ($i = $startIndex; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            // T_NS_SEPARATOR (\), T_STRING (имя), T_NAME_QUALIFIED (PHP 8+)
            if (is_array($token)) {
                $tokenType = $token[0];
                $tokenValue = $token[1];

                if (in_array($tokenType, [T_NS_SEPARATOR, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $className .= $tokenValue;
                } else {
                    // Достигли конца имени класса (например, открывающая скобка '(')
                    break;
                }
            } else {
                // Простой символ (например, '(')
                break;
            }
        }

        return $className !== '' ? $className : null;
    }
}