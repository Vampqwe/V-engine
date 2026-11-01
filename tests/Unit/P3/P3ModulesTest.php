<?php
declare(strict_types=1);

namespace Tests\Unit\P3;

use Core\Contract\HealthCheckInterface;
use Core\Contract\HealthCheckInterface as HC;
use Core\Exception\EngineException;
use Core\Exception\PortabilityException;
use Core\Module\Config\Config;
use Core\Module\DataPortability\FileDataPorter;
use Core\Module\Doctor\Doctor;
use Core\Module\FeatureFlag\FileFeatureFlags;
use Core\Module\FileStorage\FileStorage;
use Core\Module\MaintenanceMode\FileMaintenanceMode;
use PHPUnit\Framework\TestCase;

/**
 * P3-модули (Шаг 6): FeatureFlag, MaintenanceMode, Doctor, DataPortability.
 */
final class P3ModulesTest extends TestCase
{
    private string $root;
    private FileStorage $storage;
    private Config $config;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/lex-p3-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o755, true);
        $this->storage = FileStorage::create($this->root);
        $this->config = new Config();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    // ── FeatureFlag ───────────────────────────────────────────────────────

    public function testFeatureFlagDefaultsFromConfigSection(): void
    {
        $this->config->set('features', [
            'new-checkout' => ['enabled' => true],
            'beta-ui'      => ['enabled' => false],
            'legacy'       => true, // скалярный default тоже валиден
        ]);
        $flags = new FileFeatureFlags($this->storage, $this->config);

        self::assertTrue($flags->enabled('new-checkout'));
        self::assertFalse($flags->enabled('beta-ui'));
        self::assertTrue($flags->enabled('legacy', 'user-1'));
        self::assertFalse($flags->enabled('unknown.flag'), 'неизвестный флаг выключен по умолчанию');
    }

    public function testOverrideTakesPriorityOverDefaultAndPersists(): void
    {
        $this->config->set('features', ['x' => ['enabled' => true]]);
        $flags = new FileFeatureFlags($this->storage, $this->config);
        $flags->set('x', false);

        self::assertFalse($flags->enabled('x'));
        // пережил пересоздание экземпляра (диск — источник истины)
        $again = new FileFeatureFlags($this->storage, $this->config);
        self::assertFalse($again->enabled('x'));
        self::assertFileExists($this->root . '/flags/x.json');
    }

    public function testPercentageRolloutIsDeterministicPerBucket(): void
    {
        $flags = new FileFeatureFlags($this->storage, $this->config);
        $flags->setPercentage('rollout', 50);

        $first = $flags->enabled('rollout', 'user-42');
        for ($i = 0; $i < 5; $i++) {
            self::assertSame($first, $flags->enabled('rollout', 'user-42'), 'один bucket — стабильный ответ');
        }
    }

    public function testPercentageZeroAndHundredBoundaries(): void
    {
        $flags = new FileFeatureFlags($this->storage, $this->config);
        $flags->setPercentage('off', 0);
        $flags->setPercentage('on', 100);
        self::assertFalse($flags->enabled('off', 'any'));
        self::assertTrue($flags->enabled('on', 'any'));
    }

    public function testInvalidKeyRejected(): void
    {
        $flags = new FileFeatureFlags($this->storage, $this->config);
        $this->expectException(PortabilityException::class);
        $flags->set('../../etc/passwd', true);
    }

    public function testPercentageOutOfRangeThrows(): void
    {
        $flags = new FileFeatureFlags($this->storage, $this->config);
        $this->expectException(PortabilityException::class);
        $flags->setPercentage('a.b', 101);
    }

    public function testRemoveFallsBackToDefault(): void
    {
        $this->config->set('features', ['y' => ['enabled' => true]]);
        $flags = new FileFeatureFlags($this->storage, $this->config);
        $flags->set('y', false);
        $flags->remove('y');
        self::assertTrue($flags->enabled('y'));
    }

    public function testFeatureFlagHealthOk(): void
    {
        $flags = new FileFeatureFlags($this->storage, $this->config);
        $report = $flags->healthCheck();
        self::assertSame('FeatureFlag', $report['name']);
        self::assertSame(HC::STATUS_OK, $report['status']);
    }

    // ── MaintenanceMode ───────────────────────────────────────────────────

    public function testMaintenanceOffByDefault(): void
    {
        $mm = new FileMaintenanceMode($this->storage, $this->config);
        self::assertFalse($mm->active());
        self::assertFalse($mm->blocksRequest(null));
    }

    public function testEnableBlocksAllRequestsWithoutToken(): void
    {
        $mm = new FileMaintenanceMode($this->storage, $this->config);
        $mm->enable('Ищем блох', 1800);

        self::assertTrue($mm->active());
        self::assertTrue($mm->blocksRequest(null));
        self::assertTrue($mm->blocksRequest('wrong-token'));
        $state = $mm->state();
        self::assertSame('Ищем блох', $state['message']);
        self::assertSame(1800, $state['retry_after']);
    }

    public function testBypassTokenWorksOnlyWhenConfigured(): void
    {
        $mm = new FileMaintenanceMode($this->storage, $this->config);
        $mm->enable();
        // токен не настроен — bypass невозможен никогда
        self::assertTrue($mm->blocksRequest('anything'));

        $this->config->set('maintenance.bypass_token', 's3cret');
        $fresh = new FileMaintenanceMode($this->storage, $this->config);
        self::assertFalse($fresh->blocksRequest('s3cret'), 'валидный токен обходит блокировку');
        self::assertTrue($fresh->blocksRequest('s3cre'));
    }

    public function testDisableResumesNormalService(): void
    {
        $mm = new FileMaintenanceMode($this->storage, $this->config);
        $mm->enable();
        $mm->disable();
        self::assertFalse($mm->active());
        self::assertFalse($mm->blocksRequest(null));
    }

    public function testMaintenanceHealthWarningWhileActive(): void
    {
        $mm = new FileMaintenanceMode($this->storage, $this->config);
        self::assertSame(HC::STATUS_OK, $mm->healthCheck()['status']);
        $mm->enable();
        $fresh = new FileMaintenanceMode($this->storage, $this->config);
        self::assertSame(HC::STATUS_WARNING, $fresh->healthCheck()['status'], 'активный режим честно виден Doctor');
    }

    public function testCorruptedStateFileFailsHealth(): void
    {
        $this->storage->save('maintenance.json', '{not json');
        $mm = new FileMaintenanceMode($this->storage, $this->config);
        self::assertSame(HC::STATUS_FAIL, $mm->healthCheck()['status']);
        self::assertFalse($mm->active(), 'битый файл не включает режим молча');
    }

    // ── Doctor ────────────────────────────────────────────────────────────

    public function testDoctorAggregatesStatuses(): void
    {
        $doctor = new Doctor();
        $doctor->register(new HealthCheckStub('A', HC::STATUS_OK));
        $doctor->register(new HealthCheckStub('B', HC::STATUS_WARNING));
        $d = $doctor->diagnose();
        self::assertSame(HC::STATUS_WARNING, $d['status']);
        self::assertSame(['ok' => 1, 'warning' => 1, 'fail' => 0], $d['summary']);
    }

    public function testAnyFailDominatesOverallVerdict(): void
    {
        $doctor = new Doctor();
        $doctor->register(new HealthCheckStub('A', HC::STATUS_OK));
        $doctor->register(new HealthCheckStub('C', HC::STATUS_FAIL));
        self::assertSame(HC::STATUS_FAIL, $doctor->diagnose()['status']);
    }

    public function testCrashingCheckConvertedToFailWithoutPropagation(): void
    {
        $doctor = new Doctor();
        $bad = new class implements HealthCheckInterface {
            public function getHealthName(): string { return 'Bad'; }
            public function healthCheck(): array { throw new \RuntimeException('boom'); }
        };
        $doctor->register($bad);
        $d = $doctor->diagnose();
        self::assertSame(HC::STATUS_FAIL, $d['status']);
        self::assertStringContainsString('crashed', $d['checks'][0]['message']);
    }

    public function testMalformedReportTreatedAsFail(): void
    {
        $doctor = new Doctor();
        $junk = new class implements HealthCheckInterface {
            public function getHealthName(): string { return 'Junk'; }
            public function healthCheck(): array { return ['nope' => true]; }
        };
        $doctor->register($junk);
        self::assertSame(HC::STATUS_FAIL, $doctor->diagnose()['checks'][0]['status']);
    }

    public function testUnknownCheckNameThrows(): void
    {
        $doctor = new Doctor();
        $this->expectException(EngineException::class);
        $doctor->check('Ghost');
    }

    public function testReportRendersTextLines(): void
    {
        $doctor = new Doctor();
        $doctor->register(new HealthCheckStub('Disk', HC::STATUS_OK));
        self::assertStringContainsString('Disk', $doctor->report());
        self::assertStringContainsString('Вердикт', $doctor->report());
    }

    // ── DataPortability ───────────────────────────────────────────────────

    public function testExportRedactsSecrets(): void
    {
        $this->config->set('app.name', 'demo');
        $this->config->set('app_secret', 'super-secret-value');
        $this->config->set('database.password', 'p@ss');
        $porter = new FileDataPorter($this->storage, $this->config);

        $json = $porter->export(['config']);
        self::assertStringNotContainsString('super-secret-value', $json);
        self::assertStringNotContainsString('p@ss', $json);
        self::assertStringContainsString('demo', $json);
    }

    public function testEnvSectionIsForbiddenForExport(): void
    {
        $porter = new FileDataPorter($this->storage, $this->config);
        $this->expectException(PortabilityException::class);
        $porter->export(['env']);
    }

    public function testImportRejectsAbsolutePathsBeforeAnyWrite(): void
    {
        $packet = json_encode([
            'format'   => 'lex-portability',
            'version'  => 1,
            'sections' => ['config' => ['upload_dir' => '/etc']],
        ], JSON_THROW_ON_ERROR);
        $porter = new FileDataPorter($this->storage, $this->config);

        try {
            $porter->import($packet);
            self::fail('absolute path must be rejected (§20)');
        } catch (PortabilityException $e) {
            self::assertStringContainsString('Absolute path', $e->getMessage());
            // значение НЕ должно попасть в рантайм-конфиг
            self::assertNull($this->config->get('upload_dir', null));
        }
    }

    public function testImportRoundtripAppliesFlags(): void
    {
        $flags = new FileFeatureFlags($this->storage, $this->config);
        $flags->set('checkout.v2', true);

        $porter = new FileDataPorter($this->storage, $this->config, $flags);
        $packet = $porter->export(['config', 'flags']);

        // «второй сайт»: чистый конфиг + свои флаги
        mkdir($this->root . '/site-b', 0o755, true);
        $siteB = FileStorage::create($this->root . '/site-b');
        $targetConfig = new Config();
        $targetConfig->set('features', ['checkout.v2' => ['enabled' => false]]);
        $targetFlags = new FileFeatureFlags($siteB, $targetConfig);
        $targetPorter = new FileDataPorter($siteB, $targetConfig, $targetFlags);

        $applied = $targetPorter->import($packet);
        self::assertContains('flags', $applied);
        self::assertTrue($targetFlags->enabled('checkout.v2'), 'перенос флага между сайтами работает');
    }

    public function testImportGarbageRejected(): void
    {
        $porter = new FileDataPorter($this->storage, $this->config);
        $this->expectException(PortabilityException::class);
        $porter->import('<html>not a packet</html>');
    }

    public function testPortabilityHealthProbeOk(): void
    {
        $porter = new FileDataPorter($this->storage, $this->config);
        self::assertSame(HC::STATUS_OK, $porter->healthCheck()['status']);
    }
}

final class HealthCheckStub implements HealthCheckInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $status,
        private readonly string $message = 'ok',
    ) {
    }

    public function getHealthName(): string
    {
        return $this->name;
    }

    public function healthCheck(): array
    {
        return ['name' => $this->name, 'status' => $this->status, 'message' => $this->message, 'context' => []];
    }
}
