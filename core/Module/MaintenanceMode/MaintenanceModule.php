<?php
declare(strict_types=1);

namespace Core\Module\MaintenanceMode;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\KernelInterface;
use Core\Contract\MaintenanceModeInterface;
use Core\Contract\MiddlewareInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\RequestInterface;
use Core\Response;

/**
 * Модуль MaintenanceMode (P3, §8.4). Зависит от Config + FileStorage.
 * Экземпляр режима — singleton в контейнере; ядро поднимает его как
 * глобальный middleware до диспатча маршрута (§16: модуль предоставляет
 * сервис, Kernel решает вопрос порядка слоёв).
 */
final class MaintenanceModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'MaintenanceMode';
    }

    public function getVersion(): string
    {
        return FileMaintenanceMode::VERSION;
    }

    public function getDependencies(): array
    {
        return [ConfigInterface::class, FileStorageInterface::class];
    }

    public function getProvides(): array
    {
        return [MaintenanceModeInterface::class, MiddlewareInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        $storage = $container->get(FileStorageInterface::class);
        assert($config instanceof ConfigInterface);
        assert($storage instanceof FileStorageInterface);

        $mode = new FileMaintenanceMode($storage, $config);
        $container->instance(MaintenanceModeInterface::class, $mode);
        // Ядро поднимает системные middleware сразу после boot() модуля
        // (globalMiddleware принимается только до boot Kernel'а — §16/§27).
        $kernel->addSystemMiddleware(new MaintenanceMiddleware($mode));
    }
}

/**
 * Middleware режима техработ: 503 + Retry-After для всех, кроме holders
 * bypass-токена (?token=... или X-Maintenance-Token). Админ-путь не является
 * исключением по умолчанию — доступ админа только через токен (§31: никаких
 * «магических» обходов по IP/пути без явной конфигурации).
 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly MaintenanceModeInterface $mode)
    {
    }

    public function handle(RequestInterface $request, callable $next): object
    {
        if ($this->mode->blocksRequest($this->token($request))) {
            $state = $this->mode->state();
            return Response::html(
                '<!doctype html><html lang="ru"><head><title>Технические работы</title></head>'
                . '<body style="font-family:system-ui;text-align:center;padding:4rem">'
                . '<h1>🛠 Технические работы</h1><p>' . htmlspecialchars($state['message'], ENT_QUOTES) . '</p>'
                . '</body></html>',
                503,
            )->withHeader('Retry-After', (string) max(1, $state['retry_after']));
        }
        return $next($request);
    }

    private function token(RequestInterface $request): ?string
    {
        $header = $request->getHeader('x-maintenance-token');
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $query = $request->getQuery('token');
        return is_string($query) && $query !== '' ? $query : null;
    }
}
