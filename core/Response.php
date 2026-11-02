<?php
declare(strict_types=1);

namespace Core;

/**
 * Ответ ядра (ШАГ 3). Простой иммутабельный value object: статус, заголовки,
 * тело. View-модуль (P1/ШАГ 4) будет возвращать этот же тип — Kernel::send()
 * единственное место, где ответ превращается в HTTP-выход.
 */
final class Response
{
    /** @var array<string, string> Заголовки: имя => значение (канонический вид). */
    private array $headers = [];

    /** @var string[] Все Set-Cookie строки (заголовок может повторяться — RFC 6265). */
    private array $setCookieHeaders = [];

    public function __construct(
        private int $status = 200,
        private string $body = '',
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[self::canonicalName((string) $name)] = (string) $value;
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return new self($status, $encoded, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function notFound(string $body = 'Not Found'): self
    {
        return self::text($body, 404);
    }

    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'Method Not Allowed', [
            'Allow' => implode(', ', $allowed),
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[self::canonicalName($name)]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[self::canonicalName($name)] = $value;
        return $clone;
    }

    /**
     * Добавить заголовок, НЕ затирая уже выставленный с тем же именем.
     * Нужно для Set-Cookie: HTTP допускает несколько строк этого заголовка
     * (например, SID-кука и LEX_SUPERADMIN выдаются в одном ответе).
     */
    public function withAddedHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $key = self::canonicalName($name);
        if ($key === 'Set-Cookie') {
            // Множественные куки живут в специальном списке (send() печатает
            // их отдельными header(..., false) строками).
            $clone->setCookieHeaders[] = $value;
            if (!isset($clone->headers[$key])) {
                $clone->headers[$key] = $value; // совместимость getHeaders()
            }
            return $clone;
        }
        $clone->headers[$key] = ($clone->headers[$key] ?? '') !== ''
            ? $clone->headers[$key] . ', ' . $value
            : $value;
        return $clone;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** @return string[] все Set-Cookie строки ответа (в порядке выдачи) */
    public function getSetCookieHeaders(): array
    {
        return $this->setCookieHeaders;
    }

    /**
     * Выдан ли в этом ответе Set-Cookie с указанным именем? Используется ядром,
     * чтобы не перетирать SID-куку обработчика (regenerate) своей выдачей (§9).
     */
    public function hasCookie(string $name): bool
    {
        $needle = strtolower(trim($name)) . '=';
        foreach ($this->setCookieHeaders as $cookie) {
            if (str_starts_with(strtolower($cookie), $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function canonicalName(string $name): string
    {
        return implode('-', array_map(
            static fn (string $word): string => ucfirst(strtolower($word)),
            explode('-', strtolower($name)),
        ));
    }
}
