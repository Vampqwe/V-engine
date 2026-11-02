<?php
declare(strict_types=1);

namespace Core\Module\Admin;

use Core\Contract\SuperAdminAuthenticatorInterface;

/**
 * Аутентификация супер-админа ядра — БЕЗ БД (§8.4, принятые решения сессии).
 *
 * Модель:
 *   - учётка = файл <engine>/system/admin.key (JSON: login + Argon2id-хеш),
 *     создаётся ТОЛЬКО через CLI `php bin/console.php admin:passwd` —
 *     дефолтных паролей не существует;
 *   - сессия = подписанная HMAC-cookie: payload(base64url) + '.' + hmac,
 *     секрет — APP_SECRET сайта (falls back к CORE_SECRET движка);
 *   - перебор = файловый lockout по IP в <engine>/system/lockout/<hash>.json
 *     (5 неудач → блок на 15 минут, окно учёта — 10 минут).
 *
 * Все операции — атомарные запись + chmod 0600; чтение tolerant к гонкам.
 */
final class SuperAdminAuth implements SuperAdminAuthenticatorInterface
{
    public const VERSION = '1.0.0';

    /** Имя cookie панели супер-админа */
    public const COOKIE_NAME = 'LEX_SUPERADMIN';

    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900;       // 15 минут
    private const WINDOW_SECONDS = 600;        // окно накопления неудач
    private const TOKEN_TTL = 7200;            // 2 часа жизнь сессии

    public function __construct(
        private readonly string $systemDir,
        private readonly string $secret,
    ) {
        if (strlen($secret) < 16) {
            throw new \Core\Exception\SecurityException(
                'SuperAdminAuth requires a secret of at least 16 characters (APP_SECRET / CORE_SECRET).'
            );
        }
    }

    // =========================================================================
    // Interface
    // =========================================================================

    public function isConfigured(): bool
    {
        return $this->loadKeyFile() !== null;
    }

    public function attempt(string $login, string $password, string $ip = ''): bool
    {
        if ($this->isLockedOut($ip)) {
            return false;
        }
        $data = $this->loadKeyFile();
        if ($data === null) {
            return false; // ключ не настроен — вход закрыт до `admin:passwd`
        }
        $okLogin = hash_equals((string) $data['login'], $login);
        $okPass = password_verify($password, (string) $data['hash']);
        if ($okLogin && $okPass) {
            $this->resetFailures($ip);
            return true;
        }
        $this->registerFailure($ip);
        return false;
    }

    public function validateToken(?string $rawCookie): bool
    {
        if (!is_string($rawCookie) || $rawCookie === '') {
            return false;
        }
        $dot = strrpos($rawCookie, '.');
        if ($dot === false) {
            return false;
        }
        $payload = substr($rawCookie, 0, $dot);
        $sig = substr($rawCookie, $dot + 1);
        if (!hash_equals($this->sign($payload), $sig)) {
            return false;
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) {
            return false;
        }
        $claims = json_decode($json, true);
        if (!is_array($claims)) {
            return false;
        }
        $exp = $claims['exp'] ?? null;
        $iat = $claims['iat'] ?? null;
        if (!is_int($exp) || !is_int($iat)) {
            return false;
        }
        $now = time();
        return $now >= $iat && $now < $exp;
    }

    public function issueToken(): string
    {
        $data = $this->loadKeyFile();
        $payload = json_encode([
            'sub' => $data['login'] ?? 'superadmin',
            'iat' => time(),
            'exp' => time() + self::TOKEN_TTL,
            'jti' => bin2hex(random_bytes(8)),
        ], JSON_UNESCAPED_SLASHES);
        assert(is_string($payload));
        $b64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return $b64 . '.' . $this->sign($b64);
    }

    public function passwordNeedsRehash(): bool
    {
        $data = $this->loadKeyFile();
        return $data !== null && password_needs_rehash((string) $data['hash'], PASSWORD_ARGON2ID);
    }

    public function isLockedOut(string $ip = ''): bool
    {
        return $this->lockoutRemaining($ip) > 0;
    }

    public function lockoutRemaining(string $ip = ''): int
    {
        $state = $this->loadLockout($ip);
        if ($state === null) {
            return 0;
        }
        $fails = array_values(array_filter(
            (array) ($state['fails'] ?? []),
            static fn ($t): bool => is_int($t) && $t > time() - self::WINDOW_SECONDS,
        ));
        if (count($fails) < self::MAX_ATTEMPTS) {
            return 0;
        }
        $newest = max($fails);
        return max(0, $newest + self::LOCKOUT_SECONDS - time());
    }

    // =========================================================================
    // Static helpers (CLI + boot)
    // =========================================================================

    /** Абсолютный путь каталога system/ движка. */
    public static function systemDir(): string
    {
        $base = defined('ENGINE_BASE_PATH') ? ENGINE_BASE_PATH : dirname(__DIR__, 3);
        return rtrim($base, '/\\') . '/system';
    }

    public static function keyFilePath(): string
    {
        return self::systemDir() . '/admin.key';
    }

    /**
     * Создать/обновить ключ супер-админа (только из CLI!). Argon2id, chmod 0600.
     */
    public static function writeKey(string $login, string $plainPassword): void
    {
        if (strlen($plainPassword) < 8) {
            throw new \Core\Exception\SecurityException('Password too short: minimum 8 characters.');
        }
        if (preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $login) !== 1) {
            throw new \Core\Exception\SecurityException('Login must match /^[A-Za-z0-9._@-]{3,64}$/.');
        }
        $hash = password_hash($plainPassword, PASSWORD_ARGON2ID);
        if ($hash === false) {
            $hash = password_hash($plainPassword, PASSWORD_BCRYPT);
        }
        $dir = self::systemDir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \Core\Exception\SecurityException('Cannot create system directory: ' . $dir);
        }
        $json = json_encode(['login' => $login, 'hash' => $hash], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        assert(is_string($json));
        self::atomicWrite(self::keyFilePath(), $json . "\n");
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @return array{login:string,hash:string}|null */
    private function loadKeyFile(): ?array
    {
        $raw = @file_get_contents(self::keyFilePath());
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['login'], $data['hash'])) {
            return null;
        }
        return ['login' => (string) $data['login'], 'hash' => (string) $data['hash']];
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }

    private function lockoutFile(string $ip): string
    {
        $dir = self::systemDir() . '/lockout';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir . '/' . substr(hash_sha256($ip !== '' ? $ip : 'unknown'), 0, 32) . '.json';
    }

    /** @return array{fails:int[]}|null */
    private function loadLockout(string $ip): ?array
    {
        $raw = @file_get_contents($this->lockoutFile($ip));
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function registerFailure(string $ip): void
    {
        $state = $this->loadLockout($ip) ?? [];
        $fails = array_values(array_filter(
            (array) ($state['fails'] ?? []),
            static fn ($t): bool => is_int($t) && $t > time() - self::WINDOW_SECONDS,
        ));
        $fails[] = time();
        @file_put_contents(
            $this->lockoutFile($ip),
            json_encode(['fails' => $fails]),
            LOCK_EX,
        );
    }

    private function resetFailures(string $ip): void
    {
        @unlink($this->lockoutFile($ip));
    }

    private static function atomicWrite(string $path, string $content): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \Core\Exception\SecurityException('Cannot write: ' . $path);
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \Core\Exception\SecurityException('Cannot replace: ' . $path);
        }
    }
}
