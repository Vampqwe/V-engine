<?php
declare(strict_types=1);

namespace Tests\Unit\Registry;

use Core\Contract\ConfigInterface;
use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\ModuleInterface;
use Core\Exception\EngineException;
use Core\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Простая фикстура-модуль внутри теста: имя, зависимости, provides — из args.
 */
final class FakeModule implements ModuleInterface
{
    public function __construct(
        private readonly string $name,
        private readonly array $dependencies = [],
        private readonly array $provides = [],
        private readonly ?array $health = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /** @return string[] */
    public function getProvides(): array
    {
        return $this->provides;
    }

    public function healthCheck(): array
    {
        return $this->health ?? ['status' => 'ok'];
    }

    public function boot(KernelInterface $kernel): void
    {
    }
}

final class RegistryTest extends TestCase
{
    public function testRegisterAndGetAndHas(): void
    {
        $registry = new Registry();
        $module = new FakeModule('Alpha');
        $registry->register($module);

        self::assertTrue($registry->has('Alpha'));
        self::assertSame($module, $registry->get('Alpha'));
        self::assertSame(['Alpha' => $module], $registry->all());
    }

    public function testDuplicateNameRejected(): void
    {
        $registry = new Registry();
        $registry->register(new FakeModule('Alpha'));
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('already registered');
        $registry->register(new FakeModule('Alpha'));
    }

    public function testEmptyNameRejected(): void
    {
        $registry = new Registry();
        $this->expectException(EngineException::class);
        $registry->register(new FakeModule(''));
    }

    public function testGetMissingModuleThrows(): void
    {
        $registry = new Registry();
        $this->expectException(EngineException::class);
        $registry->get('Ghost');
    }

    public function testProviderGraphByExplicitProvides(): void
    {
        $registry = new Registry();
        $registry->register(new FakeModule('Config', [], [ConfigInterface::class]));
        self::assertSame('Config', $registry->providerOf(ConfigInterface::class));
        self::assertNull($registry->providerOf(LoggerInterface::class));
    }

    public function testContractConflictIsErrorNotSilentOverride(): void
    {
        $registry = new Registry();
        $registry->register(new FakeModule('First', [], [ConfigInterface::class]));
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('provided by both');
        $registry->register(new FakeModule('Second', [], [ConfigInterface::class]));
    }

    public function testBootOrderRespectsDependencyGraph(): void
    {
        $registry = new Registry();
        // Регистрируем «с конца» — порядок вывода обязан быть графовым, не регистрационным.
        $registry->register(new FakeModule('Logger', [ConfigInterface::class], [LoggerInterface::class]));
        $registry->register(new FakeModule('Config', [], [ConfigInterface::class]));

        $order = array_map(
            static fn (ModuleInterface $m): string => $m->getName(),
            $registry->resolveBootOrder(),
        );
        self::assertSame(['Config', 'Logger'], $order);
    }

    public function testCircularDependencyReportsPath(): void
    {
        $registry = new Registry();
        $registry->register(new FakeModule('A', ['Bc'], ['Ac']));
        $registry->register(new FakeModule('B', ['Ac'], ['Bc']));

        try {
            $registry->resolveBootOrder();
            self::fail('EngineException expected');
        } catch (EngineException $e) {
            self::assertStringContainsString('Circular module dependencies: A -> B -> A', $e->getMessage());
        }
    }

    public function testUnknownDependencyDoesNotBlockOrderButShowsInInspect(): void
    {
        $registry = new Registry();
        $registry->register(new FakeModule('Lonely', [LoggerInterface::class]));
        $order = $registry->resolveBootOrder();
        self::assertCount(1, $order);

        $report = $registry->inspect();
        self::assertSame([LoggerInterface::class], $report[0]['missing']);
    }

    public function testInspectIncludesHealthForDuckTypedModules(): void
    {
        $registry = new Registry();
        $registry->register(new FakeModule('Healthy', [], [], ['status' => 'degraded']));
        $report = $registry->inspect();
        self::assertSame(['status' => 'degraded'], $report[0]['health']);
    }

    public function testNameAliasResolution(): void
    {
        // Модуль предоставляет RouterInterface по соглашению имени (без getProvides)
        $module = new class implements ModuleInterface {
            public function getName(): string
            {
                return 'Router';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDependencies(): array
            {
                return [];
            }

            public function boot(KernelInterface $kernel): void
            {
            }
        };

        $registry = new Registry();
        $registry->register($module);
        self::assertSame('Router', $registry->providerOf(\Core\Contract\RouterInterface::class));
    }
}
