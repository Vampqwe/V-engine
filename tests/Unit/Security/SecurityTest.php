<?php
declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Exception\SecurityException;
use Core\Module\Security\Security;
use Core\Contract\SessionInterface;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789abcdef';

    private function session(): SessionInterface
    {
        return new class implements SessionInterface {
            /** @var array<string,mixed> */
            private array $d = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->d[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->d[$key] = $value;
            }

            public function has(string $key): bool
            {
                return isset($this->d[$key]);
            }

            public function remove(string $key): void
            {
                unset($this->d[$key]);
            }

            public function destroy(): void
            {
                $this->d = [];
            }

            public function regenerate(): void
            {
            }

            public function id(): string
            {
                return 'test-sid';
            }
        };
    }

    private function security(): Security
    {
        return new Security(self::SECRET, $this->session());
    }

    public function testShortSecretIsRejected(): void
    {
        $this->expectException(SecurityException::class);
        new Security('tiny', $this->session());
    }

    public function testCsrfTokenStableWithinSessionAndValidates(): void
    {
        $sec = $this->security();
        $token = $sec->csrfToken();
        self::assertSame($token, $sec->csrfToken(), 'токен стабилен в рамках сессии');
        self::assertTrue($sec->validateCsrf($token));
    }

    public function testCsrfRejectsForeignAndTamperedTokens(): void
    {
        $sec = $this->security();
        $real = $sec->csrfToken();

        self::assertFalse($sec->validateCsrf(null));
        self::assertFalse($sec->validateCsrf(''));
        self::assertFalse($sec->validateCsrf('garbage'));
        // Токен, подписанным другим секретом (чужой сайт многосайтовости):
        $other = new Security('other-secret-0123456789!', $this->session());
        self::assertFalse($sec->validateCsrf($other->csrfToken()));
        // Подмена случайной части при чужом HMAC:
        [$rand] = explode('.', $real);
        self::assertFalse($sec->validateCsrf($rand . '.' . str_repeat('0', 64)));
    }

    public function testRotationInvalidatesOldToken(): void
    {
        $sec = $this->security();
        $old = $sec->csrfToken();
        $new = $sec->rotateCsrf();
        self::assertNotSame($old, $new);
        self::assertTrue($sec->validateCsrf($new));
        self::assertFalse($sec->validateCsrf($old), 'ротация обязана гасить старый токен');
    }

    public function testPasswordHashingRoundtrip(): void
    {
        $sec = $this->security();
        $hash = $sec->hashPassword('hunter2!');
        self::assertNotSame('hunter2!', $hash);
        self::assertTrue($sec->verifyPassword('hunter2!', $hash));
        self::assertFalse($sec->verifyPassword('wrong', $hash));
    }

    public function testEscapeHtmlNeutralizesXss(): void
    {
        $sec = $this->security();
        self::assertSame(
            '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
            $sec->escapeHtml('<script>alert("x")</script>'),
        );
        self::assertSame('&amp;#39;', $sec->escapeHtml("&#39;"));
    }

    public function testRandomTokenLengthAndBounds(): void
    {
        $sec = $this->security();
        self::assertSame(64, strlen($sec->randomToken(32)));
        $this->expectException(SecurityException::class);
        $sec->randomToken(0);
    }
}
