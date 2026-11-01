<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Результат валидации (§16.2 Конституции). Иммутабельный value object.
 */
final class ValidationResult
{
    /** @param array<string, list<string>> $errors поле => список сообщений */
    public function __construct(
        private readonly array $errors,
        private readonly array $validatedData,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, list<string>> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** Сообщения для одного поля (пустой массив, если ошибок нет). */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /** @return array<string, mixed> данные, прошедшие валидацию (с нормализацией) */
    public function getValidatedData(): array
    {
        return $this->validatedData;
    }
}
