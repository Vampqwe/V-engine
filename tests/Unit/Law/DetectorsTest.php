<?php
declare(strict_types=1);

namespace Tests\Unit\Law;

use Law\Contract\DetectorInterface;
use Law\Detector\DirectFileLogDetector;
use Law\Detector\EmergencyBunkerDetector;
use Law\Detector\NewCoreInstantiationDetector;
use Law\Detector\StrictTypesDetector;
use Law\Detector\UnsafeOutputDetector;
use PHPUnit\Framework\TestCase;

/**
 * Self-test детекторов Конституционного Суда (ЭТАП 7).
 * Каждый детектор обязан ловить нарушение на фикстуре-нарушителе
 * и молчать на безопасной фикстуре — иначе Закон фиктивен.
 */
final class DetectorsTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/Fixture/Law/' . $name;
        self::assertFileExists($path, "Фикстура {$name} должна существовать");
        return $path;
    }

    /** Путь фикстуры должен попадать в USER-пространство логики детектора. */
    private function userPath(string $name): string
    {
        // Детекторы с scope=USER игнорируют law/, core/ и т.п.; эмулируем сайт.
        return '/sites/demo.localhost/storage/views/' . $name;
    }

    public static function detectorProvider(): array
    {
        return [
            'C-040 UnsafeOutput' => [new UnsafeOutputDetector(), 'violation.phtml', 'safe.phtml'],
        ];
    }

    /**
     * @dataProvider detectorProvider
     */
    public function testDetectsViolationAndIgnoresSafe(
        DetectorInterface $detector,
        string $violationFixture,
        string $safeFixture
    ): void {
        $bad = $this->fixture($violationFixture);
        $violation = $detector->check($this->userPath($violationFixture), (string) file_get_contents($bad), []);
        self::assertNotNull($violation, get_class($detector) . ' обязан поймать нарушение');
        self::assertSame($detector->getRuleId(), $violation->ruleId);

        $good = $this->fixture($safeFixture);
        self::assertNull(
            $detector->check($this->userPath($safeFixture), (string) file_get_contents($good), []),
            get_class($detector) . ' не должен ловить безопасный код'
        );
    }

    public function testUnsafeOutputIgnoresNonPhtmlFiles(): void
    {
        $detector = new UnsafeOutputDetector();
        $content = "<?php\n\$x = <?= \$raw ?>;\n";
        self::assertNull($detector->check('/sites/demo.localhost/some.php', $content, []));
    }

    public function testUnsafeOutputAllowsMustacheAutoescaping(): void
    {
        $detector = new UnsafeOutputDetector();
        $content = "<div>{{ \$userInput }}</div>\n";
        self::assertNull($detector->check('/sites/a/storage/views/tpl.phtml', $content, []));
    }

    public function testStrictTypesDetectorFlagsMissingDeclaration(): void
    {
        $detector = new StrictTypesDetector();
        $violation = $detector->check('/core/Foo.php', "<?php\n\nclass Foo {}\n", []);
        self::assertNotNull($violation);
        self::assertSame('C-009', $violation->ruleId);
    }

    public function testStrictTypesDetectorPassesCompliantFile(): void
    {
        $detector = new StrictTypesDetector();
        self::assertNull($detector->check('/core/Foo.php', "<?php\ndeclare(strict_types=1);\n", []));
    }

    public function testEmergencyBunkerRequiresShutdownHandlerInEntryPoint(): void
    {
        $detector = new EmergencyBunkerDetector();
        $violation = $detector->check('/public/index.php', "<?php\ndeclare(strict_types=1);\n// пусто\n", []);
        self::assertNotNull($violation, 'index.php без аварийного люка (§31) обязан быть пойман');

        $compliant = "<?php\ndeclare(strict_types=1);\nregister_shutdown_function(static function (): void {});\ndefine('ENGINE_BASE_PATH', __DIR__ . '/..');\n";
        self::assertNull($detector->check('/public/index.php', $compliant, []));
    }

    public function testEmergencyBunkerIgnoresOtherFiles(): void
    {
        $detector = new EmergencyBunkerDetector();
        self::assertNull($detector->check('/core/Kernel.php', "<?php\n// no bunker here\n", []));
    }

    public function testNewCoreInstantiationDetectorFlagsUserSpaceOnly(): void
    {
        $detector = new NewCoreInstantiationDetector();
        $bad = "<?php\n\$k = new \\Core\\Kernel();\n";
        self::assertNotNull($detector->check('/sites/demo.localhost/bootstrap/x.php', $bad, []));
        // ядро имеет право создавать свои объекты
        self::assertNull($detector->check('/core/Admin/Panel.php', $bad, []));
    }

    public function testDirectFileLogDetectorFlagsFilePutContents(): void
    {
        $detector = new DirectFileLogDetector();
        $bad = "<?php\nfile_put_contents('/tmp/app.log', 'secret token abc');\n";
        self::assertNotNull($detector->check('/sites/demo.localhost/module/Logger.php', $bad, []));
        $ok = "<?php\nrequire __DIR__ . '/lib.php';\necho 1;\n";
        self::assertNull($detector->check('/sites/demo.localhost/module/Clean.php', $ok, []));
    }

    public function testManifestRulesAllHaveRegisteredDetectors(): void
    {
        $manifest = require dirname(__DIR__, 3) . '/law/manifest.php';
        $engine = new \Law\RuleEngine(dirname(__DIR__, 3) . '/law/manifest.php');

        $ref = new \ReflectionProperty(\Law\RuleEngine::class, 'detectors');
        $ref->setAccessible(true);
        $detectors = $ref->getValue($engine);

        foreach (array_keys($manifest['rules']) as $ruleId) {
            self::assertArrayHasKey($ruleId, $detectors, "Правило {$ruleId} объявлено, но детектор не зарегистрирован");
        }
    }
}
