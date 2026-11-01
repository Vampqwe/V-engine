<?php
declare(strict_types=1);

namespace Tests\Unit\Container;

use Core\Contract\ContainerInterface;
use Core\Contract\LoggerInterface;
use Core\Container;
use Core\Exception\CircularDependencyException;
use Core\Exception\ContainerException;
use Core\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Container\CycleLeft;
use Tests\Fixture\Container\CycleRight;
use Tests\Fixture\Container\DepAInterface;
use Tests\Fixture\Container\NeedsContract;
use Tests\Fixture\Container\NoDeps;
use Tests\Fixture\Container\WireLeaf;
use Tests\Fixture\Container\WireMid;
use Tests\Fixture\Container\WithScalarDefault;
use Tests\Fixture\Container\WithUntypedRequiredParam;

/**
 * Unit-тесты DI-контейнера (ШАГ 1 плана реализации).
 */
final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        NeedsContract::$ctorCalls = 0;
    }

    // ---------------------------------------------------------------------
    // Контракт и регистрация
    // ---------------------------------------------------------------------

    public function testImplementsContainerInterface(): void
    {
        self::assertInstanceOf(ContainerInterface::class, $this->container);
    }

    public function testInstanceIsReturnedAsSingleton(): void
    {
        $logger = new LoggerStub();
        $this->container->instance(LoggerInterface::class, $logger);

        self::assertSame($logger, $this->container->get(LoggerInterface::class));
        self::assertTrue($this->container->has(LoggerInterface::class));
    }

    public function testSetFactoryResolvedOnceAsSingleton(): void
    {
        $this->container->set('svc.singleton', static fn (): LoggerStub => new LoggerStub());

        $first = $this->container->get('svc.singleton');
        $second = $this->container->get('svc.singleton');

        self::assertInstanceOf(LoggerStub::class, $first);
        self::assertSame($first, $second, 'set() даёт singleton: фабрика вызывается один раз');
    }

    public function testFactoryReturnsNewInstanceEveryGet(): void
    {
        $this->container->factory('svc.proto', static fn (): LoggerStub => new LoggerStub());

        self::assertNotSame(
            $this->container->get('svc.proto'),
            $this->container->get('svc.proto'),
            'factory() даёт новый объект на каждый get()'
        );
    }

    public function testBindContractToConcreteWithAutowiringChain(): void
    {
        $this->container->bind(DepAInterface::class, WireMid::class);

        $resolved = $this->container->get(DepAInterface::class);

        self::assertInstanceOf(WireMid::class, $resolved);
        self::assertInstanceOf(WireLeaf::class, $resolved->leaf, 'зависимость WireLeaf автовайрена по type-hint');
        self::assertSame($resolved, $this->container->get(DepAInterface::class), 'bind(): singleton-семантика');
    }

    public function testBindSelfThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->container->bind(NoDeps::class, NoDeps::class);
    }

    public function testForgetRemovesRegistration(): void
    {
        $this->container->set('x', static fn (): NoDeps => new NoDeps());
        self::assertTrue($this->container->has('x'));

        $this->container->forget('x');
        self::assertFalse($this->container->has('x'));
    }

    // ---------------------------------------------------------------------
    // Resolution / автовайринг
    // ---------------------------------------------------------------------

    public function testAutoWireInjectsRegisteredContractByTypeHint(): void
    {
        $logger = new LoggerStub();
        $this->container->instance(LoggerInterface::class, $logger);

        $service = $this->container->get(NeedsContract::class);

        self::assertSame($logger, $service->logger);
        self::assertSame(1, NeedsContract::$ctorCalls);
        self::assertSame($service, $this->container->get(NeedsContract::class));
        self::assertSame(1, NeedsContract::$ctorCalls, 'конструктор повторного get() не вызывает');
    }

    public function testScalarParameterUsesDefault(): void
    {
        $service = $this->container->get(WithScalarDefault::class);
        self::assertSame('default-mode', $service->mode);
    }

    public function testUntypedRequiredParameterFailsClearly(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot resolve parameter $anything');
        $this->container->get(WithUntypedRequiredParam::class);
    }

    public function testUnknownServiceThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->container->get('No\\Such\\Service');
    }

    public function testHasFalseForUnknownId(): void
    {
        self::assertFalse($this->container->has('No\\Such\\Service'));
        self::assertTrue($this->container->has(NoDeps::class), 'существующий класс доступен через has()');
    }

    // ---------------------------------------------------------------------
    // Циклы зависимостей
    // ---------------------------------------------------------------------

    public function testCircularDependencyThrowsWithPath(): void
    {
        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage(
            'CycleLeft -> Tests\\Fixture\\Container\\CycleRight -> Tests\\Fixture\\Container\\CycleLeft'
        );

        try {
            $this->container->get(CycleLeft::class);
        } finally {
            // Контейнер должен остаться в консистентном состоянии после цикла
            self::assertSame([], (function (): array {
                $prop = new \ReflectionProperty(Container::class, 'resolvingStack');
                $prop->setAccessible(true);
                return $prop->getValue($this->container);
            })(), 'resolvingStack очищен после исключения');
        }
    }

    // ---------------------------------------------------------------------
    // Protected-пространство ядра
    // ---------------------------------------------------------------------

    public function testProtectedServiceCannotBeOverwritten(): void
    {
        $this->container->protect('kernel.bus', static fn (): NoDeps => new NoDeps());
        self::assertTrue($this->container->isProtected('kernel.bus'));

        foreach (['set', 'factory'] as $method) {
            try {
                /** @phpstan-ignore-next-line callable-возврат не важен для проверки защиты */
                $this->container->{$method}('kernel.bus', static fn () => null);
                self::fail("{$method}() на защищённый сервис должен бросать исключение");
            } catch (ContainerException $e) {
                self::assertStringContainsString('protected core service', $e->getMessage());
            }
        }
    }

    public function testProtectedServiceCannotBeForgottenOrBound(): void
    {
        $this->container->protect('kernel.bus', static fn (): NoDeps => new NoDeps());

        try {
            $this->container->forget('kernel.bus');
            self::fail('forget() защищённого сервиса должен бросать исключение');
        } catch (ContainerException $e) {
            self::assertStringContainsString('protected core service', $e->getMessage());
        }

        try {
            $this->container->bind('kernel.bus', NoDeps::class);
            self::fail('bind() защищённого сервиса должен бросать исключение');
        } catch (ContainerException $e) {
            self::assertStringContainsString('protected core service', $e->getMessage());
        }
    }

    public function testFactoryReceivesContainerItself(): void
    {
        $probe = new NoDeps();
        $this->container->instance('probe', $probe);
        $this->container->set(
            'consumer',
            static fn (ContainerInterface $c): NoDeps => $c->get('probe')
        );

        self::assertSame($probe, $this->container->get('consumer'));
    }
}
