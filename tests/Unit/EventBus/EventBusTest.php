<?php
declare(strict_types=1);

namespace Tests\Unit\EventBus;

use Core\Contract\EventBusInterface;
use Core\EventBus;
use Core\Exception\EngineException;
use PHPUnit\Framework\TestCase;

final class EventBusTest extends TestCase
{
    private EventBus $bus;

    protected function setUp(): void
    {
        $this->bus = new EventBus();
    }

    public function testImplementsContract(): void
    {
        self::assertInstanceOf(EventBusInterface::class, $this->bus);
    }

    public function testDispatchWithoutListenersReturnsEmptyResult(): void
    {
        $result = $this->bus->dispatch('onRequestStart', ['a' => 1]);
        self::assertSame(['results' => [], 'errors' => []], $result);
        self::assertFalse($this->bus->hasListeners('onRequestStart'));
    }

    public function testListenerReceivesPayloadAndResultIsKeyed(): void
    {
        $this->bus->listen('onRouteResolved', static fn (array $p): string => 'got:' . $p['id']);
        $result = $this->bus->dispatch('onRouteResolved', ['id' => 42]);
        self::assertArrayHasKey('onRouteResolved:0:0', $result['results']);
        self::assertSame('got:42', $result['results']['onRouteResolved:0:0']);
        self::assertTrue($this->bus->hasListeners('onRouteResolved'));
    }

    public function testPriorityOrderDeterministicHigherFirst(): void
    {
        $order = [];
        $this->bus->listen('evt', static function () use (&$order): void {
            $order[] = 'low';
        }, -10);
        $this->bus->listen('evt', static function () use (&$order): void {
            $order[] = 'default';
        });
        $this->bus->listen('evt', static function () use (&$order): void {
            $order[] = 'high-1';
        }, 100);
        $this->bus->listen('evt', static function () use (&$order): void {
            $order[] = 'high-2';
        }, 100);

        $this->bus->dispatch('evt');
        // priority DESC, внутри одного приоритета — порядок регистрации (стабильно)
        self::assertSame(['high-1', 'high-2', 'default', 'low'], $order);
    }

    public function testFailingListenerDoesNotBreakChainAndIsReported(): void
    {
        $called = false;
        $this->bus->listen('evt', static function (): void {
            throw new \RuntimeException('listener boom');
        }, 10);
        $this->bus->listen('evt', static function () use (&$called): void {
            $called = true;
        });

        $result = $this->bus->dispatch('evt');
        self::assertTrue($called, 'Остальные слушатели обязаны отработать');
        self::assertSame(['listener boom'], $result['errors']);
    }

    public function testFailureIsLoggedViaLazyLogger(): void
    {
        $logger = new class implements \Core\Contract\LoggerInterface {
            /** @var array<int, array{level:string,message:string}> */
            public array $records = [];

            public function channel(string $name): \Core\Contract\LoggerInterface
            {
                return $this;
            }

            public function info(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'info', 'message' => $message];
            }

            public function warning(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'warning', 'message' => $message];
            }

            public function error(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }

            public function critical(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'critical', 'message' => $message];
            }
        };

        $bus = new EventBus(static fn () => $logger);
        $bus->listen('evt', static function (): void {
            throw new \RuntimeException('logged failure');
        });
        $bus->dispatch('evt');

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('eventbus.listener_failed', $logger->records[0]['message']);
        self::assertSame('evt', $logger->records[0]['context']['event']);
    }

    public function testRemoveListeners(): void
    {
        $this->bus->listen('evt', static fn (): int => 1);
        self::assertTrue($this->bus->hasListeners('evt'));
        $this->bus->removeListeners('evt');
        self::assertFalse($this->bus->hasListeners('evt'));
    }

    public function testEmptyEventNameRejected(): void
    {
        $this->expectException(EngineException::class);
        $this->bus->listen('', static fn (): null => null);
    }

    public function testKernelEventsConstantCoversArticle17(): void
    {
        foreach (['onRequestStart', 'onRouteResolved', 'onBeforeRender', 'onAfterRender',
                  'onRequestEnd', 'onError', 'health.check'] as $event) {
            self::assertContains($event, EventBus::KERNEL_EVENTS, "§17 event missing: $event");
        }
    }
}
