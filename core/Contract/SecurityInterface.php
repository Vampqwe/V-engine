<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт безопасности (P1, §8.2.1): CSRF, хеширование, экранирование.
 */
interface SecurityInterface
{
    // --- CSRF -----------------------------------------------------------

    /** Текущий токен: из сессии или новый (с привязкой к сайту). */
    public function csrfToken(): string;

    /** Проверка токена из запроса (пост / заголовок X-CSRF-Token). */
    public function validateCsrf(?string $token): bool;

    /** Ротация токена (после успешного входа и т.п.). */
    public function rotateCsrf(): string;

    // --- Хеширование -----------------------------------------------------

    public function hashPassword(string $plain): string;

    public function verifyPassword(string $plain, string $hash): bool;

    /** Нуждается ли хеш в апгрейд алгоритма. */
    public function needsRehash(string $hash): bool;

    // --- Экранирование ---------------------------------------------------

    /** HTML-экранирование для шаблонов (XSS). */
    public function escapeHtml(string $value): string;

    /** Случайная криптографическая строка (для сброса пароля и т.п.). */
    public function randomToken(int $bytes = 32): string;
}
