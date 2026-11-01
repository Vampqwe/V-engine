<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\ConfigInterface;
use Core\Contract\ContainerInterface;
use Core\Contract\EventBusInterface;
use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\SiteContextInterface;
use Core\Exception\ContainerException;
use Core\Module\Config\ConfigModule;
use Core\Module\FileStorage\FileStorageModule;
use Core\Module\Logger\LoggerModule;

/**
 * Ядро движка (ШАГ 2, Статья 16.8).
 *
 * Жизненный цикл:
 *   boot() — детект сайта, загрузка P0-модулей в порядке зависимостей,
 *            применение базовых настроек (timezone/debug).
 *   run()  — появится на ШАГЕ 3 (Router + EventBus + Middleware).
 *
 * Порядок загрузки модулей вычисляется топосортировкой графа «зависимости =>
 * предоставляемые контракты» (явный граф вместо магических уровней, §8.6).
 * Циклы отклоняются с указанием пути.
 */
final class Kernel implements KernelInterface
{
    public const VERSION = '0.2.0';

    private ContainerInterface $container;

    /** @var ModuleInterface[] Системные модули (детерминированный набор) */
    private array $systemModules;

    /** @var ModuleInterface[] Пользовательские модули */
    private array $userModules = [];

    private bool $booted = false;

    /** @param ?ModuleInterface[] $systemModules null => стандартный P0-набор */
    public function __construct(
        private readonly string $engineRoot,
        ?array $systemModules = null,
        private readonly array $server = [],
        private readonly array $env = [],
    ) {
        $this->systemModules = $systemModules ?? [
            new ConfigModule(),      // предоставляет ConfigInterface
            new FileStorageModule(), // зависит от Config, предоставляет FileStorageInterface
            new LoggerModule(),      // зависит от FileStorage + Config
        ];
    }

    // =========================================================================
    // Boot
    // =========================================================================

    public function boot(): void
    {
        if ($this->booted) {
            throw new ContainerException('Kernel already booted.');
        }

        $this->container = new Container();

        // 1. Инфраструктурные instance — до модулей (не являются «модулями»).
        $site = SiteContext::detect($this->engineRoot, $this->server, $this->env);
        $this->container->instance(SiteContextInterface::class, $site);
        $this->container->instance(KernelInterface::class, $this);
        $this->container->instance(ContainerInterface::class, $this->container);

        // 2. Системные модули в порядке топосортировки графа зависимостей.
        //    Внутри boot() ядро ещё не поднято — модули получают контейнер
        //    напрямую через getContainer(), а доступ к сервисам — из замыканий фабрик.
        foreach ($this->topologicalSort($this->systemModules) as $module) {
            $this->bootModule($module);
        }

        // 3. Пользовательские модули (зарегистрированы до boot()).
        foreach ($this->topologicalSort($this->userModules) as $module) {
            $this->bootModule($module);
        }

        // 4. Ядро поднято: внешние потребители получают доступ к сервисам.
        $this->booted = true;

        // 5. Базовые настройки из конфига + стартовая запись в лог.
        $config = $this->container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);
        date_default_timezone_set((string) $config->get('core.timezone', 'UTC'));

        $logger = $this->container->get(LoggerInterface::class);
        assert($logger instanceof LoggerInterface);
        $logger->info('kernel.booted', [
            'version' => self::VERSION,
            'site'    => $site->getDomain(),
        ]);
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    private function bootModule(ModuleInterface $module): void
    {
        // Понятная ошибка до boot(): отсутствие зависимости внутри чужого модуля
        // не должно выглядывать как NotFoundException из ниоткуда.
        foreach ($module->getDependencies() as $dependency) {
            if (!$this->container->has($dependency)) {
                throw new ContainerException(sprintf(
                    'Module "%s" requires missing dependency "%s".',
                    $module->getName(),
                    $dependency,
                ));
            }
        }
        $module->boot($this);
    }

    // =========================================================================
    // KernelInterface
    // =========================================================================

    public function addUserModule(ModuleInterface $module): void
    {
        if ($this->booted) {
            throw new ContainerException(sprintf(
                'Cannot register module "%s" after boot.',
                $module->getName(),
            ));
        }
        foreach ($this->userModules as $existing) {
            if ($existing->getName() === $module->getName()) {
                throw new ContainerException(sprintf('Module "%s" is already registered.', $module->getName()));
            }
        }
        $this->userModules[] = $module;
    }

    public function getContainer(): ContainerInterface
    {
        // Контейнер доступен и во время boot() (модули регистрируют в нём сервисы);
        // до boot() контейнера не существует — ошибка использования.
        if (!isset($this->container)) {
            throw new ContainerException('Kernel is not booted. Call boot() first.');
        }
        return $this->container;
    }

    public function getEventBus(): ?EventBusInterface
    {
        // ШАГ 3: EventBus появится вместе с Registry/Router.
        return null;
    }

    public function get(string $interface): mixed
    {
        $this->assertBooted();
        return $this->getContainer()->get($interface);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function assertBooted(): void
    {
        if (!$this->booted) {
            throw new ContainerException('Kernel is not booted. Call boot() first.');
        }
    }

    /**
     * Стабильная топосортировка (DFS, постобходание). Модуль идёт после всех
     * модулей, предоставляющих его контрактные зависимости. Провайдер
     * определяется по getProvides() (ModuleInterface) либо по согласованному
     * имени модуля ('Logger' <=> LoggerInterface::class).
     *
     * @param ModuleInterface[] $modules
     * @return ModuleInterface[]
     */
    private function topologicalSort(array $modules): array
    {
        $byName = [];
        foreach ($modules as $module) {
            $byName[$module->getName()] = $module;
        }

        /** @var array<string, string> contract => provider name */
        $providers = [];
        foreach ($byName as $name => $module) {
            foreach (self::providesOf($module) as $contract) {
                $providers[$contract] = $name;
            }
        }

        $state = [];   // name => 0 visiting | 1 done
        $ordered = [];
        $stack = [];

        $visit = function (string $name) use (&$visit, &$state, &$ordered, &$stack, $byName, $providers): void {
            if (($state[$name] ?? -1) === 1) {
                return;
            }
            if (($state[$name] ?? -1) === 0) {
                $cycle = array_slice($stack, array_search($name, $stack, true));
                $cycle[] = $name;
                throw new ContainerException('Circular module dependencies: ' . implode(' -> ', $cycle));
            }
            $state[$name] = 0;
            $stack[] = $name;

            foreach ($byName[$name]->getDependencies() as $contract) {
                $provider = $providers[$contract] ?? null;
                if ($provider !== null && $provider !== $name) {
                    $visit($provider);
                }
            }

            array_pop($stack);
            $state[$name] = 1;
            $ordered[] = $byName[$name];
        };

        foreach (array_keys($byName) as $name) {
            $visit((string) $name);
        }
        return $ordered;
    }

    /**
     * Контракты, которые предоставляет модуль: явный getProvides() или
     * соглашение «имя модуля <=> суффикс контракта» (Logger => LoggerInterface).
     *
     * @return string[]
     */
    private static function providesOf(ModuleInterface $module): array
    {
        if (method_exists($module, 'getProvides')) {
            /** @var string[] $explicit */
            $explicit = $module->getProvides();
            return $explicit;
        }
        // Соглашение по имени: 'FileStorage' => Core\Contract\FileStorageInterface::class
        $fqcn = 'Core\\Contract\\' . $module->getName() . 'Interface';
        if (interface_exists($fqcn)) {
            return [$fqcn];
        }
        return [];
    }
}
