<?php
declare(strict_types=1);

namespace Tests\Unit\Cookie;

use Core\Exception\SecurityException;
use Core\Module\Cookie\Cookies;
use PHPUnit\Framework\TestCase;

final class CookiesTest extends TestCase
{
    private const SECRET = 'cookie-secret-0123456789ab';

    private function cookies(array $incoming = []): Cookies
    {
        return new Cookies($incoming, self::SECRET);
    }

    public function testSetThenGetReadsBackOwnValue(): void
    {
        $c = $this->cookies();
        $c->set('theme', 'dark');
        self::assertSame('dark', $c->get('theme'));
        self::assertTrue($c->has('theme'));
    }

    public function testSignedIncomingCookieIsValid(): void
    {
        $writer = $this->cookies();
        $writer->set('sid', 'abc.def');
        $signed = $writer->pendingCookies()['sid']['value'];

        $reader = $this->cookies(['sid' => $signed]);
        self::assertSame('abc.def', $reader->get('sid'));
    }

    public function testTamperedCookieIsRejected(): void
    {
        $writer = $this->cookies();
        $writer->set('role', 'user');
        $signed = (string) $writer->pendingCookies()['role']['value'];

        [$payload] = explode('.', $signed);
        $forged = rawurlencode('admin') . substr($signed, strlen($payload));
        $reader = $this->cookies(['role' => $forged]);
        self::assertNull($reader->get('role'), 'подписанное значение без валидного HMAC не читается');
        self::assertFalse($reader->has('role'));
    }

    public function testUnsignedGarbageIsRejected(): void
    {
        $reader = $this->cookies(['x' => 'plain-value-without-signature']);
        self::assertNull($reader->get('x'));
        self::assertNull($reader->get('missing'));
    }

    public function testRemoveClearsVisibility(): void
    {
        $c = $this->cookies();
        $c->set('k', 'v');
        self::assertSame('v', $c->get('k'));
        $c->remove('k');
        self::assertNull($c->get('k'), 'после remove кука не читается');
    }

    public function testDifferentSecretCannotRead(): void
    {
        $a = $this->cookies();
        $a->set('p', 'secret-data');
        $signed = (string) $a->pendingCookies()['p']['value'];

        $b = new Cookies(['p' => $signed], 'another-secret-9876543210xy');
        self::assertNull($b->get('p'));
    }

    public function testShortSecretRejected(): void
    {
        $this->expectException(SecurityException::class);
        new Cookies([], 'short');
    }
}
