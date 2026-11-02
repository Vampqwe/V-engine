<?php
declare(strict_types=1);

namespace Tests\Unit\Admin;

use Core\Module\FileStorage\FileStorage;
use PHPUnit\Framework\TestCase;

/**
 * §8.4 / §31: опубликованные шаблоны панели — регрессии, из-за которых страница
 * /admin/storage падала с 500 на живом сайте:
 *   1) двойной include helpers.php (redeclare fatal при layout + content в одном
 *      рендере) — все объявления функций обязаны быть под function_exists();
 *   2) рассинхрон эталона core/Module/Admin/templates и копий в storage сайтов —
 *      publishTemplates() сверяет содержимое с ЭТАЛОНОМ, а не только метку .version.
 */
final class AdminTemplatePublishTest extends TestCase
{
    private string $sourceDir;

    protected function setUp(): void
    {
        $this->sourceDir = dirname(__DIR__, 3) . '/core/Module/Admin/templates';
    }

    /** Каждый шаблон обязан корректно парситься (php -l на уровне токенов). */
    public function testAllTemplatesParse(): void
    {
        foreach ($this->templateFiles() as $file) {
            $tokens = @token_get_all((string) file_get_contents($file), TOKEN_PARSE);
            $this->assertIsArray($tokens, 'Template must parse: ' . basename($file));
        }
    }

    /** Идемпотентный double-include helpers.php не даёт redeclare fatal (§ regression). */
    public function testHelpersDoubleIncludeIsIdempotent(): void
    {
        $helpers = $this->sourceDir . '/helpers.php';
        require_once $helpers; // 1-й include (как из layout.phtml)
        // 2-й include тем же путем require_once уже не выполнит файл; имитируем
        // реальный путь View (обычный include двух разных шаблонов): include ещё раз
        // через copy, чтобы function_exists()-гарды были проверены по-настоящему.
        $tmp = tempnam(sys_get_temp_dir(), 'lexadm') . '.php';
        copy($helpers, $tmp);
        include $tmp;
        include $tmp;
        unlink($tmp);
        $this->assertTrue(function_exists('lex_adm_pct'), 'helpers functions available after double include');
    }

    /** Сигнатура lex_adm_pct($free, $total) — вызовы в шаблонах обязаны ей соответствовать. */
    public function testHelperSignaturesMatchTemplateCalls(): void
    {
        require_once $this->sourceDir . '/helpers.php';
        $ref = new \ReflectionFunction('lex_adm_pct');
        $this->assertSame(2, $ref->getNumberOfParameters(), 'lex_adm_pct takes free+total');
        // В эталонных шаблонах запрещён одноаргументный вызов lex_adm_pct($diskPct)
        // (регрессия 500 на /admin/storage):
        foreach ($this->templateFiles('*.phtml') as $file) {
            $src = (string) file_get_contents($file);
            $this->assertStringNotContainsString(
                'lex_adm_pct($diskPct)',
                $src,
                'storage.phtml must not call lex_adm_pct with a single percent value: ' . basename($file),
            );
        }
    }

    /**
     * Все функции, вызываемые из шаблонов, обязаны существовать после загрузки
     * helpers.php (ловим класс ошибок «call to undefined helper» до HTTP).
     */
    public function testEveryCalledHelperExists(): void
    {
        require_once $this->sourceDir . '/helpers.php';
        $called = [];
        foreach ($this->templateFiles() as $file) {
            preg_match_all('/\\\\?lex_adm_[a-z_]+\s*\(/', (string) file_get_contents($file), $m);
            foreach ($m[0] as $hit) {
                $called[] = rtrim(ltrim(trim($hit), '\\'), '(');
            }
        }
            $this->assertNotEmpty($called, 'templates must use helpers');
        foreach (array_unique($called) as $fn) {
            $this->assertTrue(function_exists($fn), 'helper missing: ' . $fn);
        }
    }

    /**
     * Интеграция с publishTemplates(): опубликованная копия, изменённая вручную,
     * переустанавливается эталонной даже при совпадающей метке .version.
     */
    public function testRepublishRestoresTamperedCopyAgainstReference(): void
    {
        $root = sys_get_temp_dir() . '/lex-adm-pub-' . bin2hex(random_bytes(4));
        mkdir($root . '/views/admin', 0o777, true);
        try {
            $storage = FileStorage::create($root);
            $method = new \ReflectionMethod(\Core\Module\Admin\AdminModule::class, 'publishTemplates');
            $module = new \Core\Module\Admin\AdminModule();
            $method->invoke($module, $storage); // 1-я публикация

            // Метка .version выставлена в актуальную, но helpers.php повреждён:
            $version = (string) file_get_contents($this->sourceDir . '/../AdminModule.php');
            preg_match("/TEMPLATE_VERSION = '([^']+)'/", $version, $m);
            $this->assertNotEmpty($m, 'TEMPLATE_VERSION constant found');
            $storage->save('views/admin/.version', $m[1]);
            $tampered = (string) $storage->load('views/admin/helpers.php');
            $storage->save('views/admin/helpers.php', str_replace('function_exists', 'function_BROKEN', $tampered));

            $method->invoke($module, $storage); // 2-я публикация обязана заметить рассинхрон

            $restored = (string) $storage->load('views/admin/helpers.php');
            $reference = (string) file_get_contents($this->sourceDir . '/helpers.php');
            $this->assertSame($reference, $restored, 'tampered copy must be restored from reference');
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /** @return string[] список файлов шаблонов */
    private function templateFiles(string $pattern = '*.phtml'): array
    {
        $files = glob($this->sourceDir . '/' . $pattern) ?: [];
        if ($pattern === '*.phtml') {
            $files[] = $this->sourceDir . '/helpers.php';
        }
        return $files;
    }
}
