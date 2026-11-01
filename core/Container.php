<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\ContainerInterface;
use Core\Exception\CircularDependencyException;
use Core\Exception\ContainerException;
use Core\Exception\NotFoundException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * DI-контейнер ядра — ПЕРВЫЙ модуль P0 (Статья 8, ШАГ 1).
 * Задаёт паттерн взаимодействия для всех остальных модулей:
 * весь движок получает зависимости ИСКЛЮЧИТЕЛЬНО через get(Contract::class).
 *
 * Возможности:
 *  - set()/instance()/factory()/bind() — регистрация (Composition Root, §11.1);
 *  - автовайринг конструктора по type-hints (классы, интерфейсы, scalar defaults);
 *  - singleton-семантика по умолчанию, factory — новый объект на каждый get();
 *  - детектор циклов с полным путём в сообщении;
 *  - protected-пространство ядра: пользовательский код не может переопределить
 *    системные сервисы (защита от подмены контрактов из public/site/module).
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable(ContainerInterface):mixed> */
    private array $bindings = [];

    /** @var array<string, object> Резолвленные синглтоны и instance()-сервисы */
    private array $instances = [];

    /** @var array<string, true> id, регистрируемые через factory() (без кеширования) */
    private array $factories = [];

    /** @var array<string, string> contract => concrete */
    private array $aliases = [];

    /** @var array<string, true> Системные сервисы ядра (защищены от перезаписи) */
    private array $protected = [];

    /** @var string[] Текущая цепочка резолва (для детектора циклов) */
    private array $resolvingStack = [];

    // =========================================================================
    // Регистрация
    // =========================================================================

    public function set(string $id, callable $factory): void
    {
        $this->guardNotProtected($id);
        unset($this->instances[$id], $this->factories[$id], $this->aliases[$id]);
        $this->bindings[$id] = $factory;
    }

    public function instance(string $id, object $instance): void
    {
        $this->guardNotProtected($id);
        unset($this->bindings[$id], $this->factories[$id], $this->aliases[$id]);
        $this->instances[$id] = $instance;
    }

    public function factory(string $id, callable $factory): void
    {
        $this->guardNotProtected($id);
        unset($this->instances[$id], $this->aliases[$id]);
        $this->bindings[$id] = $factory;
        $this->factories[$id] = true;
    }

    public function bind(string $contract, string $concrete): void
    {
        $this->guardNotProtected($contract);
        if ($contract === $concrete) {
            throw new ContainerException(sprintf('Self-binding is not allowed: "%s".', $contract));
        }
        unset($this->bindings[$contract], $this->instances[$contract], $this->factories[$contract]);
        $this->aliases[$contract] = $concrete;
    }

    public function forget(string $id): void
    {
        $this->guardNotProtected($id);
        unset($this->bindings[$id], $this->instances[$id], $this->factories[$id], $this->aliases[$id]);
    }

    /**
     * Зарегистрировать системный сервис ядра с защитой от перезаписи
     * из пользовательского пространства. Используется только Kernel'ом (§11.1).
     */
    public function protect(string $id, callable $factory): void
    {
        unset($this->instances[$id], $this->factories[$id], $this->aliases[$id]);
        $this->bindings[$id] = $factory;
        $this->protected[$id] = true;
    }

    public function isProtected(string $id): bool
    {
        return isset($this->protected[$id]);
    }

    // =========================================================================
    // Resolution
    // =========================================================================

    public function get(string $id): mixed
    {
        // Уже созданный синглтон / instance
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // Alias (bind contract => concrete): резолвим через concrete, кешируем по id
        if (isset($this->aliases[$id])) {
            $object = $this->get($this->aliases[$id]);
            $this->instances[$id] = $object;
            return $object;
        }

        // Пользовательская фабрика/привязка
        if (isset($this->bindings[$id])) {
            $object = ($this->bindings[$id])($this);
            if (!isset($this->factories[$id])) {
                $this->instances[$id] = $object;
            }
            return $object;
        }

        // Автовайринг существующего класса
        if (class_exists($id)) {
            $object = $this->resolve($id);
            $this->instances[$id] = $object;
            return $object;
        }

        throw new NotFoundException(sprintf(
            'Service "%s" is not registered in the container and is not an existing class.',
            $id
        ));
    }

    public function has(string $id): bool
    {
        // Интерфейсы не «существуют» как классы — для них проверка existence
        // бессмысленна: контракт доступен, только если явно зарегистрирован.
        if (interface_exists($id)) {
            return isset($this->instances[$id])
                || isset($this->bindings[$id])
                || isset($this->aliases[$id]);
        }

        return isset($this->instances[$id])
            || isset($this->bindings[$id])
            || isset($this->aliases[$id])
            || class_exists($id);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Рекурсивный автовайринг конструктора класса.
     */
    private function resolve(string $className): object
    {
        if (in_array($className, $this->resolvingStack, true)) {
            $cycleStart = array_search($className, $this->resolvingStack, true);
            throw CircularDependencyException::inPath(
                array_merge(array_slice($this->resolvingStack, (int) $cycleStart), [$className])
            );
        }

        if (!class_exists($className)) {
            throw new NotFoundException(sprintf('Class "%s" does not exist.', $className));
        }

        $this->resolvingStack[] = $className;

        try {
            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                throw new ContainerException(sprintf(
                    'Cannot auto-wire abstract type "%s". Register a binding or use bind().',
                    $className
                ));
            }

            if (!$reflection->isInstantiable()) {
                throw new ContainerException(sprintf('Class "%s" is not instantiable.', $className));
            }

            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return new $className();
            }

            $arguments = $this->resolveParameters($className, $constructor->getParameters());

            return $reflection->newInstanceArgs($arguments);
        } finally {
            array_pop($this->resolvingStack);
        }
    }

    /**
     * @param ReflectionParameter[] $parameters
     * @return array<int, mixed>
     */
    private function resolveParameters(string $ownerClass, array $parameters): array
    {
        $arguments = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            // Без type-hint или non-named variadic — только default
            if ($type === null || !$type instanceof ReflectionNamedType || $parameter->isVariadic()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }
                throw new ContainerException(sprintf(
                    'Cannot resolve parameter $%s of %s::__construct(): no type hint and no default value.',
                    $parameter->getName(),
                    $ownerClass
                ));
            }

            $typeName = $type->getName();

            // Встроенные типы (string|int|array|...): берём default, иначе ошибка
            if ($type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }
                throw new ContainerException(sprintf(
                    'Cannot resolve scalar parameter $%s (%s) of %s::__construct(): no default value.',
                    $parameter->getName(),
                    $typeName,
                    $ownerClass
                ));
            }

            // Класс/интерфейс → рекурсивно через контейнер (тот же путь: bind/factory/get)
            try {
                $arguments[] = $this->get($typeName);
            } catch (NotFoundException $e) {
                if ($parameter->allowsNull()) {
                    $arguments[] = null;
                    continue;
                }
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }
                throw new ContainerException(sprintf(
                    'Cannot auto-wire "%s" for parameter $%s of %s::__construct(): %s',
                    $typeName,
                    $parameter->getName(),
                    $ownerClass,
                    $e->getMessage()
                ), previous: $e);
            }
        }

        return $arguments;
    }

    private function guardNotProtected(string $id): void
    {
        if (isset($this->protected[$id])) {
            throw new ContainerException(sprintf(
                'Service "%s" is a protected core service and cannot be re-registered outside Kernel.',
                $id
            ));
        }
    }
}
