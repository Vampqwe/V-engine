<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\ModuleInterface;
use Core\Exception\EngineException;

/**
 * Реестр модулей (ШАГ 3, §8.1 п.3, Статья 25).
 *
 * Хранит системные и пользовательские модули, строит граф «контракт =>
 * провайдер», вычисляет порядок загрузки топосортировкой (DFS) и даёт
 * срез реестра для Doctor/HealthCheck (§7, §25).
 *
 * Ядро (Kernel) делегирует сюда всю модульную механику — само ядро
 * остаётся оркестратором, а не хранилищем state.
 */
final class Registry
{
    public const VERSION = '1.0.0';

    /** @var array<string, ModuleInterface> name => module */
    private array $modules = [];

    /** @var array<string, string> contract FQCN => provider module name */
    private array $providers = [];

    /** @var array<string, string> имя alias (Route/EventBus...) => FQCN контракта */
    private const KNOWN_ALIASES = [
        'Container'  => \Core\Contract\ContainerInterface::class,
        'EventBus'   => \Core\Contract\EventBusInterface::class,
        'Router'     => \Core\Contract\RouterInterface::class,
        'Config'     => \Core\Contract\ConfigInterface::class,
        'FileStorage'=> \Core\Contract\FileStorageInterface::class,
        'Logger'     => \Core\Contract\LoggerInterface::class,
        'SiteContext'=> \Core\Contract\SiteContextInterface::class,
    ];

    /**
     * Зарегистрировать модуль. Дубликат имени — ошибка (§12: единая точка
     * регистрации, молчаливое перезаписывание запрещено).
     */
    public function register(ModuleInterface $module): void
    {
        $name = $module->getName();
        if ($name === '') {
            throw new EngineException('Module name cannot be empty.');
        }
        if (isset($this->modules[$name])) {
            throw new EngineException(sprintf('Module "%s" is already registered.', $name));
        }
        $this->modules[$name] = $module;
        foreach ($this->providesOf($module) as $contract) {
            // Первый провайдер контракта выигрывает; конфликт — явная ошибка,
            // а не тихое замещение (принцип детерминизма движка).
            if (isset($this->providers[$contract]) && $this->providers[$contract] !== $name) {
                throw new EngineException(sprintf(
                    'Contract "%s" is provided by both "%s" and "%s".',
                    $contract,
                    $this->providers[$contract],
                    $name,
                ));
            }
            $this->providers[$contract] = $name;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    public function get(string $name): ModuleInterface
    {
        if (!isset($this->modules[$name])) {
            throw new EngineException(sprintf('Module "%s" is not registered.', $name));
        }
        return $this->modules[$name];
    }

    /** @return array<string, ModuleInterface> name => module (в порядке регистрации) */
    public function all(): array
    {
        return $this->modules;
    }

    /** Контракт => имя модуля-провайдера (или null). */
    public function providerOf(string $contract): ?string
    {
        return $this->providers[$contract] ?? null;
    }

    /**
     * Порядок загрузки: модули идут после всех провайдеров своих зависимостей.
     * Стабильная сортировка: при равенстве приоретенов сохраняется порядок
     * регистрации. Цикл отклоняется с указанием пути.
     *
     * @return ModuleInterface[]
     */
    public function resolveBootOrder(): array
    {
        $state = [];   // name => 0 visiting | 1 done
        $ordered = [];
        $stack = [];

        $visit = function (string $name) use (&$visit, &$state, &$ordered, &$stack): void {
            if (($state[$name] ?? -1) === 1) {
                return;
            }
            if (($state[$name] ?? -1) === 0) {
                $cycle = array_slice($stack, array_search($name, $stack, true));
                $cycle[] = $name;
                throw new EngineException('Circular module dependencies: ' . implode(' -> ', $cycle));
            }
            $state[$name] = 0;
            $stack[] = $name;

            foreach ($this->modules[$name]->getDependencies() as $dependency) {
                $provider = $this->resolveProvider($dependency);
                if ($provider !== null && $provider !== $name) {
                    $visit($provider);
                }
            }

            array_pop($stack);
            $state[$name] = 1;
            $ordered[] = $this->modules[$name];
        };

        foreach (array_keys($this->modules) as $name) {
            $visit($name);
        }

        return $ordered;
    }

    /**
     * Срез для Doctor/HealthCheck (§25): имя, версия, зависимости.
     * Модули, реализующие Core\Contract\HealthCheckInterface (появится на
     * ШАГЕ 4 вместе с самодиагностикой), добавляют свой статус по duck-typing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inspect(): array
    {
        $report = [];
        foreach ($this->modules as $name => $module) {
            $entry = [
                'name'         => $name,
                'version'      => $module->getVersion(),
                'dependencies' => $module->getDependencies(),
                'missing'      => [],
            ];
            foreach ($module->getDependencies() as $dependency) {
                if ($this->resolveProvider($dependency) === null) {
                    $entry['missing'][] = $dependency;
                }
            }
            if (method_exists($module, 'healthCheck')) {
                /** @var array<string, mixed> $health */
                $health = $module->healthCheck();
                $entry['health'] = $health;
            }
            $report[] = $entry;
        }
        return $report;
    }

    /**
     * Провайдер зависимости: точное совпадение FQCN, затем алиасы известных
     * инфраструктурных имён ('Router' => RouterInterface::class).
     */
    private function resolveProvider(string $dependency): ?string
    {
        if (isset($this->providers[$dependency])) {
            return $this->providers[$dependency];
        }
        $alias = self::KNOWN_ALIASES[$dependency] ?? null;
        if ($alias !== null) {
            return $this->providers[$alias] ?? null;
        }
        return null;
    }

    /**
     * Контракты модуля: явный getProvides() либо соглашение
     * «имя => Core\Contract\<Name>Interface» (как в Kernel ШАГА 2).
     *
     * @return string[]
     */
    private function providesOf(ModuleInterface $module): array
    {
        if (method_exists($module, 'getProvides')) {
            /** @var string[] $explicit */
            $explicit = $module->getProvides();
            return $explicit;
        }
        $fqcn = 'Core\\Contract\\' . $module->getName() . 'Interface';
        if (interface_exists($fqcn)) {
            return [$fqcn];
        }
        return [];
    }
}
