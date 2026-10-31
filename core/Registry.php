<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\ModuleInterface;

/**
 * P0: Реестр модулей (Статья 19). Единственная точка регистрации — Kernel
 * через addUserModule()/boot() (Статья 12), поэтому register() — внутренний API.
 */
final class Registry
{
    /** @var array<string, ModuleInterface> name -> module */
    private array $modules = [];

    /** @var array<string, string> contract FQCN -> module name */
    private array $providedBy = [];

    /** Внутренний API ядра. */
    public function register(ModuleInterface $module): void
    {
        $name = $module->getName();
        if (isset($this->modules[$name])) {
            throw new \RuntimeException("Модуль уже зарегистрирован: {$name}");
        }
        $this->modules[$name] = $module;
        // Модуль может объявить предоставляемый контракт (getProvidedContract)
        if (method_exists($module, 'getProvidedContract')) {
            /** @var string $contract */
            $contract = $module->getProvidedContract();
            $this->providedBy[$contract] = $name;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    public function get(string $name): ModuleInterface
    {
        if (!isset($this->modules[$name])) {
            throw new \RuntimeException("Модуль не найден: {$name}");
        }
        return $this->modules[$name];
    }

    /** @return list<ModuleInterface> */
    public function all(): array
    {
        return array_values($this->modules);
    }

    /**
     * Топологическая сортировка по getDependencies() (Статья 8.6):
     * зависимости boot-ятся раньше модуля. Цикл = RuntimeException (FAIL).
     *
     * @return list<ModuleInterface>
     */
    public function sortedByDependencies(): array
    {
        $sorted = [];
        /** @var array<string,int> $state name -> 0 visiting | 1 done */
        $state = [];

        $visit = function (string $name) use (&$visit, &$state, &$sorted): void {
            if (($state[$name] ?? -1) === 1) {
                return;
            }
            if (($state[$name] ?? -1) === 0) {
                throw new \RuntimeException("Циклическая зависимость модулей через: {$name}");
            }
            $state[$name] = 0;
            foreach ($this->modules[$name]->getDependencies() as $dependency) {
                $depName = $this->providedBy[$dependency] ?? null;
                if ($depName !== null && $depName !== $name) {
                    $visit($depName);
                }
            }
            $state[$name] = 1;
            $sorted[] = $this->modules[$name];
        };

        foreach (array_keys($this->modules) as $name) {
            $visit((string) $name);
        }
        return $sorted;
    }
}
