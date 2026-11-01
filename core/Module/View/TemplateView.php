<?php
declare(strict_types=1);

namespace Core\Module\View;

use Core\Contract\SecurityInterface;
use Core\Contract\ViewInterface;
use Core\Exception\EngineException;

/**
 * P1-модуль View (§8.2.5): чистые PHP-шаблоны jailed-root FileStorage.
 *
 * render() изолирован: шаблон получает только переданные $data и $esc
 * (шорткат Security::escapeHtml). include внутри extract() НЕ даёт доступа
 * к локальным переменным метода. Несуществующий шаблон — понятное исключение.
 */
final class TemplateView implements ViewInterface
{
    public const VERSION = '1.0.0';

    public function __construct(
        private readonly \Core\Contract\FileStorageInterface $storage,
        private readonly SecurityInterface $security,
        private readonly string $viewDir = 'views',
    ) {
    }

    public function exists(string $template): bool
    {
        return $this->storage->exists($this->path($template));
    }

    public function render(string $template, array $data = []): string
    {
        $full = $this->resolve($template);
        if (!is_file($full)) {
            throw new EngineException(sprintf('Template "%s" not found.', $template));
        }
        return self::capture($full, $data, $this->security);
    }

    /** Отрендерить строковый шаблон (для писем/сниппетов). */
    public function renderString(string $code, array $data = []): string
    {
        return self::evaluate($code, $data, $this->security);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** Шаблонный путь: только относительный, без выхода за jailed-root. */
    private function path(string $template): string
    {
        $t = ltrim(strtr($template, '\\', '/'), '/');
        if ($t === '' || !preg_match('#^[A-Za-z0-9_\-./ ]+\.phtml$#', $t)) {
            throw new EngineException(sprintf('Invalid template name "%s".', $template));
        }
        if (str_contains($t, '..')) {
            throw new EngineException('Template traversal is forbidden.');
        }
        return $this->viewDir . '/' . $t;
    }

    private function resolve(string $template): string
    {
        // FileStorage::resolve уже гарантирует jailed-root.
        return $this->storage->resolve($this->path($template));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function capture(string $file, array $data, SecurityInterface $security): string
    {
        $render = static function (string $__file, array $__data) use ($security): string {
            extract($__data, EXTR_SKIP);
            $esc = static fn (mixed $v): string => $security->escapeHtml((string) $v);
            ob_start();
            try {
                include $__file;
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                throw $e;
            }
        };
        return $render($file, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function evaluate(string $code, array $data, SecurityInterface $security): string
    {
        $render = static function (string $__code, array $__data) use ($security): string {
            extract($__data, EXTR_SKIP);
            $esc = static fn (mixed $v): string => $security->escapeHtml((string) $v);
            ob_start();
            try {
                eval($__code);
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                throw $e;
            }
        };
        return $render($code, $data);
    }
}
