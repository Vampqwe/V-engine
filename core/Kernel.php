<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\ContainerInterface;
use Core\Contract\EventBusInterface;
use Core\Contract\KernelInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\SiteContextInterface;
use Core\Module\Config\ConfigModule;
use Core\Module\FileStorage\FileStorageModule;
use Core\Module\Logger\LoggerModule;

/**
 * P0: Ядро — точка входа и Composition Root (Статья 11.1).
 * Порядок boot() фиксирован Статьями 8.5 и 32: перехватчики ошибок — первыми
 * (слот закроют P1 ErrorHandler/ExceptionHandler), затем Router, затем всё остальное.
 */
final class Kernel implements KernelInterface
{
    private ContainerInterface $container;
    private EventBusInterface $eventBus;
    private Registry $registry;
    private Router $router;
    private bool $booted = false;

    public function __construct(private readonly SiteContextInterface $context)
    {
        $this->container = new Container();
        $this->eventBus = new EventBus();
        $this->registry = new Registry();
        $this->router = new Router();

        // Инфраструктура ядра доступна в контейнере сразу
        $this->container->instance(SiteContextInterface::class, $context);
        $this->container->instance(KernelInterface::class, $this);
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(EventBusInterface::class, $this->eventBus);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(Registry::class, $this->registry);
    }

    /** Бутстрап ядра в конституционном порядке (Статьи 8.5, 32). */
    public function boot(): void
    {
        if ($this->booted) {
            throw new \LogicException('Ядро уже запущено');
        }

        // --- Статья 32: ПЕРВЫМИ — registerErrorHandler()/registerExceptionHandler()
        // (реализуются P1-модулями на ШАГЕ 2; слот зарезервирован до Router и прочих) ---

        // --- P0: FileStorage -> Logger -> Config (Статья 8.5) ---
        $this->registry->register(new FileStorageModule());
        $this->registry->register(new LoggerModule());
        $this->registry->register(new ConfigModule());

        // Boot всех модулей в порядке зависимостей (Статья 8.6)
        foreach ($this->registry->sortedByDependencies() as $module) {
            $module->boot($this);
        }

        $this->booted = true;
        $this->eventBus->dispatch('onRequestStart');
    }

    /** Единственный способ регистрации пользовательских модулей (Статья 12). */
    public function addUserModule(ModuleInterface $module): void
    {
        $this->registry->register($module);
        if ($this->booted) {
            $module->boot($this);
        }
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function getEventBus(): EventBusInterface
    {
        return $this->eventBus;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getRegistry(): Registry
    {
        return $this->registry;
    }

    public function get(string $interface): mixed
    {
        return $this->container->get($interface);
    }
}
