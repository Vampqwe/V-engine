<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\ContainerInterface;

/**
 * P0: DI-контейнер ядра (Статья 11 — единственный способ получения сервисов).
 * Composition Root (Статья 11.1): make() легален только внутри ядра и Lex.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /** Регистрирует готовый экземпляр (singleton). */
    public function instance(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            if (class_exists($id)) {
                return $this->instances[$id] = $this->make($id);
            }
            throw new \RuntimeException("Сервис не зарегистрирован в контейнере: {$id}");
        }
        if (isset($this->resolving[$id])) {
            throw new \RuntimeException("Циклическая зависимость при создании: {$id}");
        }
        $this->resolving[$id] = true;
        try {
            return $this->instances[$id] = ($this->factories[$id])($this);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances) || class_exists($id);
    }

    public function make(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("Класс не найден: {$class}");
        }
        $reflector = new \ReflectionClass($class);
        if (!$reflector->isInstantiable()) {
            throw new \RuntimeException("Класс не может быть создан: {$class}");
        }
        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return new $class();
        }
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $parameters)) {
                $arguments[] = $parameters[$name];
                continue;
            }
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }
            throw new \RuntimeException(
                "Не удалось разрешить параметр \$name класса {$class}"
            );
        }
        return $reflector->newInstanceArgs($arguments);
    }
}
