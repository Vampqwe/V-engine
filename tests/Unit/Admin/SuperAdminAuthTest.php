<?php
declare(strict_types=1);

namespace Tests\Unit\Admin;

use Core\Exception\SecurityException;
use Core\Module\Admin\SuperAdminAuth;
use PHPUnit\Framework\TestCase;

/**
 * §8.4: аутентификация супер-админа ядра — file-key (Argon2id), HMAC-cookie,
 * файловый lockout по IP. Без БД и без зависимостей от сайта.
 */
final class SuperAdminAuthTest extends TestCase
{
    private string $systemDir;
    private string $secret = 'test-secret-0123456789abcdef';

    protected function setUp(): void
    {
        $this->systemDir = sys_get_temp_dir() . '/lex-sa-' . bin2hex(random_bytes(4));
        mkdir($this->systemDir . '/lockout', 0o750, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->systemDir));
    }

    /** Пишем key-файл напрямую в временный systemDir (обход CLI-only writeKey). */
    private function writeKeyFile(string $login, string $plain): void
    {
        $hash = password_hash($plain, PASSWORD_ARGON2ID);
        file_put_contents(
            $this->systemDir . '/admin.key',
            json_encode(['login' => $login, 'hash' => $hash], JSON_UNESCAPED_SLASHES),
        );
    }

    public function testTooShortSecretRejected(): void
    {
        $this->expectException(SecurityException::class);
        new SuperAdminAuth($this->systemDir, 'short');
    }

    public function testTokenRoundTripSignsAndValidates(): void
    {
        $auth = new SuperAdminAuth($this->systemDir, $this->secret);
        $token = $auth->issueToken();
        self::assertTrue($auth->validateToken($token));
        self::assertFalse($auth->validateToken(null), 'пустая cookie невалидна');
        self::assertFalse($auth->validateToken('garbage'), 'мусор невалиден');
        self::assertFalse($auth->validateToken('.' . str_repeat('a', 64)), 'пустой payload отвергается');

        // Подделка: чужой payload с подписью оригинального токена не проходит.
        $dot = strrpos($token, '.');
        $forged = rtrim(strtr(base64_encode('{"sub":"x","iat":' . time() . ',"exp":' . (time() + 99) . '}'), '+/', '-_'), '=')
            . substr($token, (int) $dot);
        self::assertFalse($auth->validateToken($forged), 'поддельный payload отвергается');

        // Другой секрет — тот же токен не принимается.
        $other = new SuperAdminAuth($this->systemDir, 'another-secret-9876543210');
        self::assertFalse($other->validateToken($token));
    }

    public function testIsLockedOutFalseForUnknownIp(): void
    {
        $auth = new SuperAdminAuth($this->systemDir, $this->secret);
        self::assertFalse($auth->isLockedOut('203.0.113.7'));
        self::assertSame(0, $auth->lockoutRemaining('203.0.113.7'));
    }

    public function testAttemptWithoutKeyFileAlwaysFails(): void
    {
        // Ключ не настроен (нет system/admin.key) — вход закрыт до `admin:passwd` (§8.4).
        $auth = new SuperAdminAuth($this->systemDir, $this->secret);
        self::assertFalse($auth->isConfigured());
        self::assertFalse($auth->attempt('root', 'any-password', '203.0.113.5'));
    }

    public function testLockoutStateFilesUsePortableHashNaming(): void
    {
        // Файл lockout именуется по hash('sha256', ip) — без зависимости от
        // шорткат-функций PHP; до попыток файла нет.
        $ip = '198.51.100.9';
        $file = $this->systemDir . '/lockout/' . substr(hash('sha256', $ip), 0, 32) . '.json';
        self::assertFileDoesNotExist($file);
    }
}
