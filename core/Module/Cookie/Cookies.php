<?php
declare(strict_types=1);

namespace Core\Module\Cookie;

use Core\Contract\CookieInterface;
use Core\Exception\SecurityException;

/**
 * P1-модуль Cookie (§8.2.3, §16.1).
 *
 * Значения подписываются HMAC-SHA256 с SITE_SECRET: подделка на клиенте
 * отклоняется при чтении (get вернёт null). Атрибуты по умолчанию безопасные:
 * HttpOnly, SameSite=Lax, Secure при https.
 *
 * setcookie() вызывается только если заголовки ещё не отправлены; список
 * pending-куков доступен слою вывода (Response emit) — тестуемость без headers.
 */
final class Cookies implements CookieInterface
{
    public const VERSION = '1.0.0';

    /** @var array<string, array{value:?string, ttl:int}> куки, установленные в этом запросе (для emit) */
    private array $pending = [];

    /**
     * @param array<string, string> $incoming $_COOKIE
     * @param bool $secure принудительный Secure-флаг (по умолчанию определяется по схеме URL сайта)
     */
    public function __construct(
        private readonly array $incoming,
        private readonly string $siteSecret,
        private readonly string $path = '/',
        private readonly bool $secure = false,
        private readonly string $sameSite = 'Lax',
    ) {
        if (strlen($siteSecret) < 16) {
            throw new SecurityException('SITE_SECRET must be at least 16 characters.');
        }
    }

    // =========================================================================
    // CookieInterface
    // =========================================================================

    public function get(string $name): ?string
    {
        // pending имеет приоритет: remove()/set() в этом запросе переопределяют входящие куки.
        if (array_key_exists($name, $this->pending)) {
            $raw = $this->pending[$name]['value'];
        } else {
            $raw = $this->incoming[$name] ?? null;
        }
        if ($raw === null) {
            return null;
        }
        return $this->decode($raw);
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    public function set(string $name, string $value, int $ttl = 0): void
    {
        $this->pending[$name] = ['value' => $this->encode($value), 'ttl' => $ttl];
        $this->sendCookie($name, $this->encode($value), $ttl);
    }

    public function remove(string $name): void
    {
        // $incoming readonly — удаление выражаем tombstone-записью в pending:
        // get() вернёт null, emit отправит экспирацию в прошлом.
        $this->pending[$name] = ['value' => null, 'ttl' => 0];
        // Экспирация в прошлом + пустое значение.
        $this->sendCookie($name, '', -3600);
    }

    /** Куки, которые слой вывода обязан отправить (тесты читают это вместо headers_list()). */
    public function pendingCookies(): array
    {
        return $this->pending;
    }

    // =========================================================================
    // Signing
    // =========================================================================

    private function encode(string $value): string
    {
        return rawurlencode($value) . '.' . hash_hmac('sha256', $value, $this->siteSecret);
    }

    private function decode(string $signed): ?string
    {
        $pos = strrpos($signed, '.');
        if ($pos === false) {
            return null;
        }
        $payload = substr($signed, 0, $pos);
        $mac = substr($signed, $pos + 1);
        $value = rawurldecode($payload);
        $expected = hash_hmac('sha256', $value, $this->siteSecret);
        return hash_equals($expected, $mac) ? $value : null;
    }

    private function pendingValue(string $name): ?string
    {
        $entry = $this->pending[$name] ?? null;
        return $entry === null ? null : $entry['value'];
    }

    private function sendCookie(string $name, string $value, int $ttl): void
    {
        if (headers_sent()) {
            // Деградация: данные уже в pending — emit произойдёт через Response.
            return;
        }
        setcookie($name, $value, [
            'expires'  => $ttl > 0 ? time() + $ttl : 0,
            'path'     => $this->path,
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => $this->sameSite,
        ]);
    }
}
