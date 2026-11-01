<?php
declare(strict_types=1);

namespace Law\Detector;

use Law\Contract\DetectorInterface;
use Law\ValueObject\Violation;

/**
 * Детектор C-020 (§20. Jail): в пользовательском пространстве (sites/) запрещены
 * прямые операции с файловой системой — весь файловый ввод-вывод обязан идти
 * через FileStorageInterface (jailed-root, контроль traversal).
 *
 * Ловим вызовы функций: fopen, file_put_contents, unlink, rename, copy, mkdir,
 * rmdir, chmod, chown, touch, symlink, link, fwrite, fread, file_get_contents*
 * (*file_get_contents разрешён для чтения конфигов/шаблонов вне хранилища —
 * помечаем как WARN, остальные — FAIL).
 */
final class DirectFsAccessDetector implements DetectorInterface
{
    /** Строго запрещённые операции записи/удаления/перемещения (§20.2) */

    private const BANNED = [
        'fopen' => true, 'fwrite' => true, 'unlink' => true, 'rename' => true,
        'copy' => true, 'mkdir' => true, 'rmdir' => true, 'chmod' => true,
        'chown' => true, 'touch' => true, 'symlink' => true, 'link' => true,
        'file_put_contents' => true, 'ftruncate' => true, 'fclose' => true,
    ];

    private const SOFT = [
        'file_get_contents' => true, 'glob' => true, 'scandir' => true,
        'is_file' => true, 'is_dir' => true, 'opendir' => true,
    ];

    public function getRuleId(): string
    {
        return 'C-020';
    }

    public function check(string $filePath, string $content, array $ruleConfig): ?Violation
    {
        if (!$this->isUserSpace($filePath)) {
            return null;
        }

        // Быстрый предварительный фильтр.
        if (!preg_match('/\b(?:fopen|fwrite|unlink|rename|copy|mkdir|rmdir|chmod|chown|touch|symlink|link|file_put_contents|ftruncate|fclose|file_get_contents|glob|scandir|opendir)\s*\(/i', $content)) {
            return null;
        }

        $tokens = token_get_all($content);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            $name = strtolower($token[1]);
            $hard = isset(self::BANNED[$name]);
            $soft = isset(self::SOFT[$name]);
            if (!$hard && !$soft) {
                continue;
            }

            // Следующий значимый токен — '(' (вызов функции), иначе это метод/константа.
            $j = $this->nextMeaningful($tokens, $i + 1);
            if ($j === null || is_array($j) === false ? false : true) { /* placeholder */ }
            if ($j === null || !is_string($j) || $j !== '(') {
                continue;
            }

            // Предыдущий значимый токен не должен быть '->', '::', 'function', '\'.
            $p = $this->prevMeaningful($tokens, $i - 1);
            if ($p !== null) {
                $pt = $tokens[$p];
                if (is_array($pt) && in_array($pt[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                if (!is_array($pt) && $pt === '\\') {
                    continue;
                }
            }

            return new Violation(
                ruleId: $this->getRuleId(),
                filePath: $filePath,
                line: $token[2],
                message: ($ruleConfig['message'] ?? 'Прямой доступ к ФС в обход FileStorageInterface')
                    . ": найден вызов {$token[1]}()",
                hint: $ruleConfig['hint'] ?? 'Используйте FileStorageInterface (load/save/delete/exists/list)',
                severity: $hard ? ($ruleConfig['severity'] ?? 'FAIL') : 'WARN'
            );
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function nextMeaningful(array $tokens, int $from): mixed
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $t;
        }
        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function prevMeaningful(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
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

        if (preg_match('#(^|/)(core|law|vendor)/#', $normalizedPath) === 1) {
            return false;
        }

        return (bool) preg_match('#(^|/)sites(/|$)#', $normalizedPath);
    }
}
