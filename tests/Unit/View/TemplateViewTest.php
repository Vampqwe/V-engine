<?php
declare(strict_types=1);

namespace Tests\Unit\View;

use Core\Exception\EngineException;
use Core\Module\FileStorage\FileStorage;
use Core\Module\Security\Security;
use Core\Module\Session\NativeSession;
use Core\Module\Session\FileSessionStore;
use Core\Module\View\TemplateView;
use PHPUnit\Framework\TestCase;

final class TemplateViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/view-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/views', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function view(string $subdir = 'views'): TemplateView
    {
        $storage = FileStorage::create($this->dir);
        $session = new NativeSession(new FileSessionStore($storage, 'sess_'));
        return new TemplateView($storage, new Security('view-secret-0123456789abc', $session), $subdir);
    }

    public function testRenderBasicTemplateWithData(): void
    {
        file_put_contents(
            $this->dir . '/views/hello.phtml',
            '<?= $esc($name) ?> / <?= $title ?? "?" ?>',
        );
        self::assertSame('World / ?', $this->view()->render('hello', ['name' => 'World']));
    }

    public function testEscapingNeutralizesXssInTemplates(): void
    {
        file_put_contents($this->dir . '/views/x.phtml', '<?= $esc($payload) ?>');
        $out = $this->view()->render('x', ['payload' => '<script>alert(1)</script>']);
        self::assertStringNotContainsString('<script>', $out);
        self::assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testExistsAndMissingTemplate(): void
    {
        file_put_contents($this->dir . '/views/on.phtml', 'ok');
        self::assertTrue($this->view()->exists('on.phtml'));
        self::assertFalse($this->view()->exists('off.phtml'));

        $this->expectException(EngineException::class);
        $this->view()->render('off.phtml');
    }

    public function testTraversalIsBlocked(): void
    {
        foreach (['../secret.phtml', '../../etc/passwd', 'a/../../../b.phtml'] as $evil) {
            try {
                $this->view()->exists($evil);
                self::fail("traversal \"{$evil}\" must be rejected");
            } catch (EngineException) {
                self::assertTrue(true);
            }
        }
    }

    public function testNonPhtmlExtensionIsRejected(): void
    {
        $this->expectException(EngineException::class);
        $this->view()->exists('shell.php');
    }

    public function testTemplateCannotLeakObjectVariables(): void
    {
        // Шаблон видит только переданные данные: локальные переменные render-цикла недоступны.
        file_put_contents($this->dir . '/views/leak.phtml', '<?= isset($storage) ? "LEAK" : "clean" ?>');
        self::assertSame('clean', $this->view()->render('leak.phtml'));
    }

    public function testRenderStringForSnippets(): void
    {
        $out = $this->view()->renderString('<?= $esc($v) ?>!', ['v' => '<b>']);
        self::assertSame('&lt;b&gt;!', $out);
    }
}
