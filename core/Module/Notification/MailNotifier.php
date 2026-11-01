<?php
declare(strict_types=1);

namespace Core\Module\Notification;

use Core\Contract\LoggerInterface;
use Core\Contract\NotificationInterface;

/**
 * Уведомления (P2). Три транспорта, выбор конфигом 'notification.driver':
 *  - log:   запись в канал Logger (dev/тесты, всегда доступен);
 *  - smtp:  raw-SMTP через stream_socket_client — БЕЗ cURL (§34.1-safe),
 *           STARTTLS поверх того же сокета, без внешних библиотек;
 *  - mail:  PHP mail() как последний fallback.
 */
final class MailNotifier implements NotificationInterface
{
    public const VERSION = '1.0.0';

    /**
     * @param array{driver?:string, from?:string, smtp_host?:string, smtp_port?:int,
     *              smtp_user?:string, smtp_pass?:string, smtp_secure?:string, timeout?:int} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function sendMail(array $to, string $subject, string $body, array $options = []): bool
    {
        $to = array_values(array_filter(array_map(
            static fn ($a): string => trim((string) $a),
            $to,
        ), static fn (string $a): bool => self::isValidEmail($a)));
        if ($to === []) {
            return false;
        }
        // Injection-защита заголовков (RFC 5322): CR/LF запрещены везде.
        $subject = str_replace(["\r", "\n"], '', $subject);
        $from = (string) ($this->config['from'] ?? 'noreply@localhost');
        if (!self::isValidEmail($from)) {
            return false;
        }

        $headers = [
            'From: ' . $from,
            'To: ' . implode(', ', $to),
            'Subject: ' . $subject,
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url($from, PHP_URL_HOST) ?: 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        foreach (['cc' => 'Cc', 'reply_to' => 'Reply-To'] as $key => $hname) {
            if (isset($options[$key])) {
                $val = is_array($options[$key]) ? implode(', ', $options[$key]) : (string) $options[$key];
                $headers[] = $hname . ': ' . str_replace(["\r", "\n"], '', $val);
            }
        }

        $encodedBody = chunk_split(base64_encode($body));
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;

        return match ($this->config['driver'] ?? 'log') {
            'smtp' => $this->sendSmtp($from, $to, $message),
            'mail' => $this->sendMailFunction($to, $subject, $body, $headers),
            default => $this->sendLog($to, $subject, $body),
        };
    }

    // =========================================================================
    // Транспорты
    // =========================================================================

    private function sendLog(array $to, string $subject, string $body): bool
    {
        if ($this->logger === null) {
            return false;
        }
        $this->logger->info('notification.mail', [
            'channel' => 'app',
            'to' => $to,
            'subject' => $subject,
            'bytes' => strlen($body),
        ]);
        return true;
    }

    private function sendMailFunction(array $to, string $subject, string $body, array $headers): bool
    {
        if (!function_exists('mail')) {
            return false;
        }
        $extraHeaders = implode("\r\n", array_slice($headers, 0, 1)); // только From
        return @mail(implode(',', $to), $subject, $body, $extraHeaders);
    }

    /**
     * Минималистичный SMTP-клиент на сокетах (EHLO → STARTTLS? → AUTH? →
     * MAIL FROM → RCPT TO → DATA → QUIT). Таймаут и обрыв соединения — false.
     */
    private function sendSmtp(string $from, array $to, string $message): bool
    {
        $host = (string) ($this->config['smtp_host'] ?? '');
        $port = (int) ($this->config['smtp_port'] ?? 587);
        $timeout = (float) ($this->config['timeout'] ?? 10);
        if ($host === '') {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($sock === false) {
            return false;
        }
        stream_set_timeout($sock, (int) $timeout);

        try {
            $reader = static function () use ($sock): ?string {
                $data = '';
                while (($line = fgets($sock, 1024)) !== false) {
                    $data .= $line;
                    // Многострочный ответ: продолжение, если 4-й символ «-».
                    if (strlen($line) < 4 || $line[3] !== '-') {
                        break;
                    }
                }
                return $data === '' ? null : $data;
            };
            $cmd = static function (string $command) use ($sock, $reader): ?string {
                if (@fwrite($sock, $command . "\r\n") === false) {
                    return null;
                }
                return $reader();
            };
            $ok = static function (?string $resp, int $code): bool {
                return $resp !== null && str_starts_with($resp, (string) $code);
            };

            if (!$ok($reader(), 220)) {
                return false;
            }
            $domain = (string) gethostname();
            if (!$ok($cmd('EHLO ' . $domain), 250)) {
                return false;
            }

            $secure = (string) ($this->config['smtp_secure'] ?? 'tls');
            if ($secure === 'tls' && $port !== 465) {
                if (!$ok($cmd('STARTTLS'), 220) || !stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return false;
                }
                if (!$ok($cmd('EHLO ' . $domain), 250)) {
                    return false;
                }
            }

            $user = (string) ($this->config['smtp_user'] ?? '');
            $pass = (string) ($this->config['smtp_pass'] ?? '');
            if ($user !== '') {
                if (!$ok($cmd('AUTH LOGIN'), 334)
                    || !$ok($cmd(base64_encode($user)), 334)
                    || !$ok($cmd(base64_encode($pass)), 235)) {
                    return false;
                }
            }

            if (!$ok($cmd('MAIL FROM:<' . $from . '>'), 250)) {
                return false;
            }
            foreach ($to as $rcpt) {
                if (!$ok($cmd('RCPT TO:<' . $rcpt . '>'), 250)) {
                    return false;
                }
            }
            if (!$ok($cmd('DATA'), 35)) {
                return false;
            }
            // Byte-stuffing: ведущая точка экранируется (RFC 5321 §4.5.2).
            $escaped = preg_replace('/^\./m', '..', $message) ?? $message;
            if (@fwrite($sock, $escaped . "\r\n.\r\n") === false || !$ok($reader(), 250)) {
                return false;
            }
            $cmd('QUIT');
            return true;
        } finally {
            fclose($sock);
        }
    }

    private static function isValidEmail(string $email): bool
    {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 320;
    }
}
