<?php
declare(strict_types=1);

namespace Core\Module\Validator;

use Core\Contract\ValidationResult;
use Core\Contract\ValidatorInterface;

/**
 * Валидатор данных (P2, §8.3.1). Строгий, без side-эффектов.
 *
 * Поддерживаемые правила (строка через '|'):
 *   required, nullable, string, int|integer, numeric, bool|boolean,
 *   array, email, url, min:N (длина/кол-во), max:N, between:A-B,
 *   in:a,b,c, regex:/.../, date (Y-m-d), same:field, different:field.
 *
 * Нормализация валидированных данных:
 *   - 'string'  => trim(); пустая строка после trim = «отсутствует»;
 *   - 'int'     => (int) для numeric-значений;
 *   - 'bool'    => true/'1'/'yes'/1, false/'0'/'no'/0;
 *   - неподходящие по типу значения дают ошибку и не попадают в validated data.
 */
final class DataValidator implements ValidatorInterface
{
    public const VERSION = '1.0.0';

    /** @var array<string, callable> кастомные правила: name => fn($value, $arg, $data): ?string */
    private array $customRules = [];

    public function extend(string $name, callable $rule): void
    {
        if ($name === '' || preg_match('/^[a-z_]+$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid rule name: {$name}");
        }
        $this->customRules[$name] = $rule;
    }

    public function validate(array $data, array $rules): ValidationResult
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $ruleString) {
            [$parsed, $flags] = $this->parseRules(is_array($ruleString) ? $ruleString : explode('|', $ruleString));

            $present = array_key_exists($field, $data);
            $value = $data[$field] ?? null;
            // «Пустое» значение: null, '' или строка из пробелов.
            $blank = $value === null || (is_string($value) && trim($value) === '');

            if (!$present) {
                if (isset($parsed['required'])) {
                    $errors[$field][] = 'The field is required.';
                }
                continue; // нет значения — остальные правила не проверяем
            }
            if ($blank && !in_array('nullable', $flags, true)) {
                // Присутствует, но пусто — required нарушен даже без явного флага:
                // пустое значение не может пройти какую-либо содержательную проверку.
                $errors[$field][] = isset($parsed['required'])
                    ? 'The field is required.'
                    : 'The field must not be empty.';
                continue;
            }
            if ($blank) {
                $validated[$field] = null;
                continue;
            }

            $cast = null;
            $typeChecked = false;
            foreach ($parsed as $ruleName => $arg) {
                $message = match ($ruleName) {
                    'required', 'nullable' => null,
                    'string' => $this->checkString($value, $arg),
                    'int', 'integer' => $this->checkInt($value, $cast, $typeChecked),
                    'numeric' => is_numeric($value) ? null : 'The value must be numeric.',
                    'bool', 'boolean' => $this->checkBool($value, $cast, $typeChecked),
                    'array' => is_array($value) ? null : 'The value must be an array.',
                    'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false ? null : 'The value must be a valid email.',
                    'url' => filter_var((string) $value, FILTER_VALIDATE_URL) !== false ? null : 'The value must be a valid URL.',
                    'min' => $this->checkMin($value, $arg),
                    'max' => $this->checkMax($value, $arg),
                    'between' => $this->checkBetween($value, $arg),
                    'in' => $this->checkIn($value, $arg),
                    'regex' => $this->checkRegex($value, $arg),
                    'date' => $this->checkDate($value),
                    'same' => (string) $value === (string) ($data[$arg] ?? '') ? null : 'The value must match field "' . $arg . '".',
                    'different' => (string) $value !== (string) ($data[$arg] ?? '') ? null : 'The value must differ from field "' . $arg . '".',
                    default => $this->runCustom($ruleName, $value, $arg, $data),
                };
                if ($message !== null) {
                    $errors[$field][] = $message;
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = $typeChecked ? $cast : (is_string($value) ? trim($value) : $value);
            }
        }

        return new ValidationResult($errors, $validated);
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * @param list<string> $raw
     * @return array{array<string, ?string>, list<string>} [rules(name=>arg), flags]
     */
    private function parseRules(array $raw): array
    {
        $rules = [];
        $flags = [];
        foreach ($raw as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            $pos = strpos($item, ':');
            if ($pos === false) {
                $rules[$item] = null;
                if ($item === 'nullable') {
                    $flags[] = $item;
                }
            } else {
                $rules[substr($item, 0, $pos)] = substr($item, $pos + 1);
            }
        }
        return [$rules, $flags];
    }

    private function checkString(mixed $value, ?string $arg): ?string
    {
        if (!is_scalar($value)) {
            return 'The value must be a string.';
        }
        return null;
    }

    private function checkInt(mixed $value, mixed &$cast, bool &$checked): ?string
    {
        if (is_int($value)) {
            $cast = $value;
            $checked = true;
            return null;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $cast = (int) trim($value);
            $checked = true;
            return null;
        }
        return 'The value must be an integer.';
    }

    private function checkBool(mixed $value, mixed &$cast, bool &$checked): ?string
    {
        if (is_bool($value)) {
            $cast = $value;
            $checked = true;
            return null;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'yes', 'true'], true)) {
                $cast = true;
                $checked = true;
                return null;
            }
            if (in_array($normalized, ['0', 'no', 'false'], true)) {
                $cast = false;
                $checked = true;
                return null;
            }
        }
        return 'The value must be boolean.';
    }

    private function sizeOf(mixed $value): float|int
    {
        if (is_array($value)) {
            return count($value);
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        return mb_strlen(trim((string) $value));
    }

    private function checkMin(mixed $value, ?string $arg): ?string
    {
        $limit = (float) ($arg ?? '0');
        return $this->sizeOf($value) >= $limit ? null : "The value must be at least {$arg}.";
    }

    private function checkMax(mixed $value, ?string $arg): ?string
    {
        $limit = (float) ($arg ?? '0');
        return $this->sizeOf($value) <= $limit ? null : "The value must not exceed {$arg}.";
    }

    private function checkBetween(mixed $value, ?string $arg): ?string
    {
        [$lo, $hi] = array_pad(explode('-', (string) $arg, 2), 2, '0');
        $size = $this->sizeOf($value);
        return ($size >= (float) $lo && $size <= (float) $hi)
            ? null
            : "The value must be between {$lo} and {$hi}.";
    }

    private function checkIn(mixed $value, ?string $arg): ?string
    {
        $allowed = explode(',', (string) $arg);
        return in_array((string) $value, $allowed, true) ? null : 'The value is not in the allowed list.';
    }

    private function checkRegex(mixed $value, ?string $arg): ?string
    {
        if ($arg === null || $arg === '') {
            return 'Misconfigured regex rule.';
        }
        $result = @preg_match($arg, (string) $value);
        if ($result === false) {
            return 'Misconfigured regex pattern.';
        }
        return $result === 1 ? null : 'The value format is invalid.';
    }

    private function checkDate(mixed $value): ?string
    {
        $trimmed = trim((string) $value);
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $trimmed);
        return ($d !== false && $d->format('Y-m-d') === $trimmed)
            ? null
            : 'The value must be a valid date (Y-m-d).';
    }

    private function runCustom(string $name, mixed $value, ?string $arg, array $data): ?string
    {
        if (!isset($this->customRules[$name])) {
            return "Unknown validation rule: {$name}";
        }
        return ($this->customRules[$name])($value, $arg, $data);
    }
}
