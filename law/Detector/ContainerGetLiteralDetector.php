<?php
declare(strict_types=1);

namespace Law\Detector;

use Law\Contract\DetectorInterface;
use Law\ValueObject\Violation;

/**
 * Детектор C-016 (§16.3): в пользовательском пространстве (sites/) запрещены
 * вызовы $container->get('...') / $kernel->get('...') со СТРОКОВЫМ ЛИТЕРАЛОМ —
 * резолвить сервисы можно только по FQN контракта (Foo::class), иначе ломается
 * переносимость и автодополнение, и нарушается единый источник истины контрактов.
 *
 * Реализация на токенах: ловим T_VARIABLE ($container/$kernel) -> T_OBJECT_OPERATOR
 * -> T_STRING (get) -> '(' -> T_CONSTANT_ENCAPSED_STRING (литерал).
 */
final class ContainerGetLiteralDetector implements DetectorInterface
{
    public function getRuleId(): string
    {
        return 'C-016';
    }

    public function check(string $filePath, string $content, array $ruleConfig): ?Violation
    {
        if (!$this->isUserSpace($filePath)) {
            return null;
        }

        // Быстрый предварительный фильтр — токенный разбор дорог.
        if (!preg_match('/\$(?:container|kernel)\s*->\s*get\s*\(\s*[\'"]/', $content)) {
            return null;
        }

        $tokens = token_get_all($content);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }
            if (!in_array(strtolower($token[1]), ['$container', '$kernel'], true)) {
                continue;
            }

            $j = $this->nextMeaningful($tokens, $i + 1);
            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $k = $this->nextMeaningful($tokens, $j + 1);
            if ($k === null || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING || $tokens[$k][1] !== 'get') {
                continue;
            }

            $m = $this->nextMeaningful($tokens, $k + 1);
            if ($m === null || $tokens[$m] !== '(') {
                continue;
            }

            $n = $this->nextMeaningful($tokens, $m + 1);
            if ($n === null || !is_array($tokens[$n]) || $tokens[$n][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            return new Violation(
                ruleId: $this->getRuleId(),
                filePath: $filePath,
                line: $tokens[$i][2],
                message: ($ruleConfig['message'] ?? 'Запрет строковых литералов в container->get()')
                    . ': найден вызов ' . $token[1] . '->get(' . $tokens[$n][1] . ')',
                hint: $ruleConfig['hint'] ?? 'Используйте $container->get(SomeContract::class) — только FQN контракта',
                severity: $ruleConfig['severity'] ?? 'FAIL'
            );
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    private function isUserSpace(string $filePath): bool
    {
        $normalizedPath = str_replace('\\', '/', $filePath);

        // Системные пространства движка — не пользовательские (§7/§11/§16.8).
        if (preg_match('#(^|/)(core|law|vendor)/#', $normalizedPath) === 1) {
            return false;
        }

        return (bool) preg_match('#(^|/)sites(/|$)#', $normalizedPath);
    }
}
