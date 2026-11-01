<?php
declare(strict_types=1);

namespace Core\Module\Security;

use Core\Contract\SecurityInterface;
use Core\Exception\SecurityException;

/**
 * P1-модуль Security (§8.2.1): CSRF, пароли, экранирование.
 *
 * CSRF-токен хранится в SessionInterface (ключ '_csrf'); проверка —
 * hash_equals (постоянное время). Токен подписан SITE_SECRET: подпись
 * проверяется без сессии, но токен из чужого сайта многосайтовой установки
 * отклоняется, а украсть его нельзя — значение не угадать.
 */
final class Security implements SecurityInterface
{
    public const VERSION = '1.0.0';

    private const SESSION_KEY = '_csrf';

    public function __construct(
        private readonly string $siteSecret,
        private readonly \Core\Contract\SessionInterface $session,
    ) {
        if (strlen($siteSecret) < 16) {
            throw new SecurityException('SITE_SECRET must be at least 16 characters.');
        }
    }

    // =========================================================================
    // CSRF
    // =========================================================================

    public function csrfToken(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || !$this->verifySignature($token)) {
            $token = $this->issueToken();
        }
        return $token;
    }

    public function validateCsrf(?string $token): bool
    {
        if ($token === null || !is_string($token) || !$this->verifySignature($token)) {
            return false;
        }
        // Двойная проверка: подпись валидна И совпадает с токеном сессии
        // (защита от "сырых" подписанных значений, подброшенных вне потока).
        $stored = $this->session->get(self::SESSION_KEY);
        return is_string($stored) && hash_equals($stored, $token);
    }

    public function rotateCsrf(): string
    {
        return $this->issueToken();
    }

    // =========================================================================
    // Пароли
    // =========================================================================

    public function hashPassword(string $plain): string
    {
        $hash = password_hash($plain, PASSWORD_ARGON2ID);
        if ($hash === false) {
            // Argon2 недоступен (старая libargon) — честный fallback bcrypt.
            $hash = password_hash($plain, PASSWORD_BCRYPT);
        }
        return $hash;
    }

    public function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    // =========================================================================
    // Экранирование / токены
    // =========================================================================

    public function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public function randomToken(int $bytes = 32): string
    {
        if ($bytes < 1 || $bytes > 1024) {
            throw new SecurityException('Token length out of bounds.');
        }
        return bin2hex(random_bytes($bytes));
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** Выпустить новый токен и записать его в сессию. */
    private function issueToken(): string
    {
        $random = bin2hex(random_bytes(16));
        $token = $random . '.' . hash_hmac('sha256', $random, $this->siteSecret);
        $this->session->set(self::SESSION_KEY, $token);
        return $token;
    }

    private function verifySignature(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || strlen($parts[0]) !== 32 || strlen($parts[1]) !== 64) {
            return false;
        }
        $expected = hash_hmac('sha256', $parts[0], $this->siteSecret);
        return hash_equals($expected, $parts[1]);
    }
}
