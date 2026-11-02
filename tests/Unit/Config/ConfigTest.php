<?php
declare(strict_types=1);

namespace Tests\Unit\Config;

use Core\Exception\ConfigException;
use Core\Module\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lex-cfg-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function writeSection(string $name, string $php): void
    {
        file_put_contents($this->dir . '/config/' . $name . '.php', "<?php\ndeclare(strict_types=1);\nreturn " . $php . ";");
    }

    public function testLoadsPhpSectionsWithDottedKeys(): void
    {
        $this->writeSection('core', "['debug' => false, 'timezone' => 'UTC']");
        $config = (new Config($this->dir . '/config'))->load();

        self::assertFalse($config->get('core.debug'));
        self::assertSame('UTC', $config->get('core.timezone'));
        self::assertSame('Nowhere', $config->get('core.missing', 'Nowhere'));
        self::assertTrue($config->has('core.debug'));
        self::assertFalse($config->has('core.missing'));
    }

    public function testNestedPathsAndWholeSection(): void
    {
        $this->writeSection('logger', "['channels' => ['app', 'error'], 'rotate' => ['max' => 5]]");
        $config = (new Config($this->dir . '/config'))->load();

        self::assertSame(['app', 'error'], $config->get('logger.channels'));
        self::assertSame(5, $config->get('logger.rotate.max'));
        self::assertIsArray($config->get('logger'));
        self::assertSame(5, $config->all('logger')['rotate']['max']);
    }

    public function testEnvOverridesPhpSections(): void
    {
        $this->writeSection('core', "['debug' => false]");
        file_put_contents($this->dir . '/.env', "# comment\nCORE_DEBUG=true\nSITE_URL=\"https://example.com\"\nexport APP_NAME='My Site'\nBAD LINE\nQUOTED=\"a \\\"b\\\" c\"\nINLINE=42 # trailing\n");

        $config = (new Config($this->dir . '/config', $this->dir . '/.env'))->load();

        self::assertTrue($config->get('core.debug'), 'ENV CORE_DEBUG must override php section');
        self::assertSame('https://example.com', $config->get('site.url'));
        self::assertSame('My Site', $config->get('app.name'));
        self::assertSame('a "b" c', $config->get('quoted'));
        self::assertSame(42, $config->get('inline'));
    }

    public function testRuntimeSetWinsOverEverything(): void
    {
        $this->writeSection('core', "['debug' => false]");
        $config = (new Config($this->dir . '/config'))->load();
        $config->set('core.debug', true);
        self::assertTrue($config->get('core.debug'));
    }

    public function testReplaceInjectsSectionWithoutFiles(): void
    {
        $config = (new Config())->load();
        $config->replace('engine', ['site_url' => 'https://x.test']);
        self::assertSame('https://x.test', $config->get('engine.site_url'));
    }

    public function testCastEnvValueTypes(): void
    {
        self::assertTrue(Config::castEnvValue('true'));
        self::assertFalse(Config::castEnvValue('FALSE'));
        self::assertNull(Config::castEnvValue('null'));
        self::assertSame(10, Config::castEnvValue('10'));
        self::assertSame(-3, Config::castEnvValue('-3'));
        self::assertSame(1.5, Config::castEnvValue('1.5'));
        self::assertSame('https://a.b', Config::castEnvValue('https://a.b'));
        self::assertSame('', Config::castEnvValue(''));
    }

    public function testMissingConfigDirIsEmptyNotFatal(): void
    {
        $config = (new Config('/nonexistent/dir'))->load();
        self::assertSame([], $config->all());
    }

    public function testNonArraySectionFileThrows(): void
    {
        $this->writeSection('bad', "'not-an-array'");
        $this->expectException(ConfigException::class);
        (new Config($this->dir . '/config'))->load();
    }

    public function testEmptyKeyRejectedOnSet(): void
    {
        $this->expectException(ConfigException::class);
        (new Config())->set('  ', 'x');
    }
}
