<?php
declare(strict_types=1);

namespace Tests\Unit\Kernel;

use Core\Contract\ConfigInterface;
use Core\Contract\ContainerInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\SiteContextInterface;
use Core\Exception\ContainerException;
use Core\Kernel;
use Core\Module\FileStorage\FileStorage;
use Core\Module\Logger\FileLogger;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Kernel\CycleModuleA;
use Tests\Fixture\Kernel\CycleModuleB;
use Tests\Fixture\Kernel\DummyService;
use Tests\Fixture\Kernel\DummySiteModule;

/**
 * Интеграция Kernel + SiteContext + P0-модулей через DI (принятая модель:
 * весь движок работает через get(Contract::class)).
 */
final class KernelBootTest extends TestCase
{
    private string $engineRoot;

    protected function setUp(): void
    {
        $this->engineRoot = sys_get_temp_dir() . '/lex-engine-' . bin2hex(random_bytes(4));
        // sites/example.test/{config,storage} — жёсткая привязка домен=>папка
        mkdir($this->engineRoot . '/sites/example.test/config', 0o755, true);
        mkdir($this->engineRoot . '/sites/example.test/storage', 0o755, true);
        file_put_contents(
            $this->engineRoot . '/sites/example.test/config/core.php',
            "<?php\ndeclare(strict_types=1);\nreturn ['timezone' => 'Europe/Berlin', 'debug' => false];",
        );
        file_put_contents(
            $this->engineRoot . '/sites/example.test/config/storage.php',
            "<?php\ndeclare(strict_types=1);\nreturn ['path' => '{$this->engineRoot}/sites/example.test/storage'];",
        );
        file_put_contents(
            $this->engineRoot . '/sites/example.test/.env',
            "SITE_URL=https://example.test\nCORE_DEBUG=true\n",
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->engineRoot));
    }

    private function makeKernel(array $server = [], array $env = []): Kernel
    {
        return new Kernel($this->engineRoot, null, $server, $env);
    }

    public function testBootResolvesAllP0Contracts(): void
    {
        $kernel = $this->makeKernel(env: ['SITE_DOMAIN' => 'example.test']);
        $kernel->boot();

        self::assertTrue($kernel->isBooted());
        self::assertInstanceOf(FileStorage::class, $kernel->get(FileStorageInterface::class));
        self::assertInstanceOf(FileLogger::class, $kernel->get(LoggerInterface::class));
        self::assertInstanceOf(\Core\Module\Config\Config::class, $kernel->get(ConfigInterface::class));
        self::assertInstanceOf(SiteContextInterface::class, $kernel->get(SiteContextInterface::class));
        self::assertSame($kernel, $kernel->get(KernelInterface::class));
        self::assertInstanceOf(ContainerInterface::class, $kernel->getContainer());
    }

    public function testSiteDetectedFromHttpHostWithPort(): void
    {
        $kernel = $this->makeKernel(server: ['HTTP_HOST' => 'example.test:8080']);
        $kernel->boot();

        $site = $kernel->get(SiteContextInterface::class);
        assert($site instanceof SiteContextInterface);
        self::assertStringEndsWith('/sites/example.test', $site->getBaseFolder());
        self::assertSame($site->getBaseFolder() . '/.env', $site->getEnvPath());
    }

    public function testConfigLayeringEnvOverridesPhpSections(): void
    {
        $kernel = $this->makeKernel(env: ['SITE_URL' => 'https://example.test']);
        $kernel->boot();
        $config = $kernel->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);

        self::assertTrue($config->get('core.debug'), '.env CORE_DEBUG=true overrides core.php');
        self::assertSame('Europe/Berlin', $config->get('core.timezone'));
        self::assertSame('https://example.test', $config->get('engine.site_url'));
    }

    public function testTimezoneAppliedFromConfig(): void
    {
        $kernel = $this->makeKernel(env: ['SITE_DOMAIN' => 'example.test']);
        $kernel->boot();
        self::assertSame('Europe/Berlin', date_default_timezone_get());
    }

    public function testBootWritesLogViaFileStorage(): void
    {
        $kernel = $this->makeKernel(env: ['SITE_DOMAIN' => 'example.test']);
        $kernel->boot();

        $files = glob($this->engineRoot . '/sites/example.test/storage/logs/app-*.log') ?: [];
        self::assertNotEmpty($files, 'kernel.booted must be logged through Logger->FileStorage');
        $line = json_decode(trim((string) file_get_contents($files[0])), true);
        self::assertSame('kernel.booted', $line['message']);
        self::assertSame('example.test', $line['context']['site']);
    }

    public function testUserModuleBootsAfterDependencies(): void
    {
        $kernel = $this->makeKernel(env: ['SITE_DOMAIN' => 'example.test']);
        $kernel->addUserModule(new DummySiteModule());
        $kernel->boot();

        self::assertInstanceOf(DummyService::class, $kernel->get(DummyService::class));
        self::assertSame('booted', $kernel->get(DummyService::class)->state);
    }

    public function testDoubleBootRejected(): void
    {
        $kernel = $this->makeKernel(env: ['SITE_DOMAIN' => 'example.test']);
        $kernel->boot();
        $this->expectException(ContainerException::class);
        $kernel->boot();
    }

    public function testAccessBeforeBootRejected(): void
    {
        $kernel = $this->makeKernel(env: ['SITE_DOMAIN' => 'example.test']);
        $this->expectException(ContainerException::class);
        $kernel->get(LoggerInterface::class);
    }

    public function testAddModuleAfterBootRejected(): void
    {
        $kernel = $this->makeKernel(env: ['SITE_DOMAIN' => 'example.test']);
        $kernel->boot();
        $this->expectException(ContainerException::class);
        $kernel->addUserModule(new DummySiteModule());
    }

    public function testDuplicateModuleNameRejected(): void
    {
        $kernel = $this->makeKernel(env: ['SITE_DOMAIN' => 'example.test']);
        $kernel->addUserModule(new DummySiteModule());
        $this->expectException(ContainerException::class);
        $kernel->addUserModule(new DummySiteModule());
    }

    public function testMissingDependencyProducesClearError(): void
    {
        $kernel = new Kernel($this->engineRoot, [new CycleModuleA()], [], ['SITE_DOMAIN' => 'example.test']);
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('requires missing dependency');
        $kernel->boot();
    }

    public function testCircularModuleGraphIsRejected(): void
    {
        $kernel = new Kernel($this->engineRoot, [new CycleModuleA(), new CycleModuleB()], [], ['SITE_DOMAIN' => 'example.test']);
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular module dependencies: CycleA -> CycleB -> CycleA');
        $kernel->boot();
    }

    public function testUnknownDomainFailsFast(): void
    {
        $kernel = $this->makeKernel(env: ['SITE_DOMAIN' => 'ghost.test']);
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('does not exist for domain');
        $kernel->boot();
    }

    public function testProtectedCoreServicesCannotBeHijacked(): void
    {
        $kernel = $this->makeKernel(env: ['SITE_DOMAIN' => 'example.test']);
        $kernel->boot();
        $container = $kernel->getContainer();
        $this->expectException(ContainerException::class);
        $container->set(LoggerInterface::class, static fn() => 'hijack');
    }
}
