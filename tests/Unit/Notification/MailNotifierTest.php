<?php
declare(strict_types=1);

namespace Tests\Unit\Notification;

use Core\Contract\LoggerInterface;
use Core\Module\Notification\MailNotifier;
use PHPUnit\Framework\TestCase;

final class MailNotifierTest extends TestCase
{
    /** @param list<array{channel:string,message:string,context:array}> $logged */
    private function spyLogger(array &$logged): LoggerInterface
    {
        return new class ($logged, 'notification') implements LoggerInterface {
            /** @param list<array{channel:string,message:string,context:array}> $ref */
            public function __construct(private array &$ref, private string $chan = 'app')
            {
            }

            public function channel(string $name): LoggerInterface
            {
                return new self($this->ref, $name);
            }

            public function info(string $message, array $context = []): void
            {
                $this->ref[] = ['channel' => $this->chan, 'message' => $message, 'context' => $context];
            }

            public function warning(string $message, array $context = []): void
            {
                $this->info($message, $context);
            }

            public function error(string $message, array $context = []): void
            {
                $this->info($message, $context);
            }

            public function critical(string $message, array $context = []): void
            {
                $this->info($message, $context);
            }
        };
    }

    public function testLogDriverRecordsMessage(): void
    {
        $logged = [];
        $n = new MailNotifier(['driver' => 'log', 'from' => 'site@example.com'], $this->spyLogger($logged));
        self::assertTrue($n->sendMail(['user@example.com'], 'Hi', 'Body text'));
        self::assertCount(1, $logged);
        self::assertSame('notification.mail', $logged[0]['message']);
        self::assertSame(['user@example.com'], $logged[0]['context']['to']);
    }

    public function testRejectsAllInvalidRecipients(): void
    {
        $logged = [];
        $n = new MailNotifier(['driver' => 'log'], $this->spyLogger($logged));
        self::assertFalse($n->sendMail(['not-an-email', '', 'a@b'], 'x', 'y'), 'без валидных адресов — false');
        self::assertSame([], $logged);
    }

    public function testStripsHeaderInjectionFromSubjectAndCc(): void
    {
        $logged = [];
        $n = new MailNotifier(['driver' => 'log', 'from' => 'ok@example.com'], $this->spyLogger($logged));
        $res = $n->sendMail(
            ['user@example.com'],
            "Sale\r\nBcc: victim@evil.com",
            'body',
            ['cc' => "a@b.com\r\nX-Injected: yes"],
        );
        self::assertTrue($res);
        self::assertStringNotContainsString("\r", $logged[0]['context']['subject']);
        self::assertStringNotContainsString("\n", $logged[0]['context']['subject']);
    }

    public function testInvalidFromAddressFailsClosed(): void
    {
        $logged = [];
        $n = new MailNotifier(['driver' => 'log', 'from' => 'broken@'], $this->spyLogger($logged));
        self::assertFalse($n->sendMail(['user@example.com'], 's', 'b'));
    }

    public function testSmtpDriverFailsGracefullyWithoutServer(): void
    {
        // Несуществующий порт → соединение не поднимается → false, без exception.
        $n = new MailNotifier([
            'driver' => 'smtp',
            'from' => 'site@example.com',
            'smtp_host' => '127.0.0.1',
            'smtp_port' => 1,
            'timeout' => 1,
        ]);
        self::assertFalse($n->sendMail(['user@example.com'], 's', 'b'));
    }

    public function testSmtpMissingHostFailsFast(): void
    {
        $n = new MailNotifier(['driver' => 'smtp', 'from' => 'site@example.com']);
        self::assertFalse($n->sendMail(['user@example.com'], 's', 'b'));
    }

    public function testLogDriverWithoutLoggerReturnsFalse(): void
    {
        $n = new MailNotifier(['driver' => 'log', 'from' => 'a@b.com']);
        self::assertFalse($n->sendMail(['user@example.com'], 's', 'b'));
    }
}
