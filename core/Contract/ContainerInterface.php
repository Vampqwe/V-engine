<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт DI-контейнера (ERRATA-002 к Конституции v4.0.0).
 *
 * Единственный легальный способ получения сервисов как ядром,
 * так и пользовательским кодом (Статья 3, Статья 11).
 * Поведение совместимо с PSR-11 (без внешнего пакета).
 */
interface ContainerInterface
{
    /**
     * Найти запись по идентификатору.
     *
     * @param string $id Имя интерфейса (контракта) или класса.
     *
     * @return mixed Экземпляр сервиса.
     *
     * @throws \Core\Exception\NotFoundException Если $id не найден и не может быть создан.
     * @throws \Core\Exception\ContainerException Ошибка резолва (в т.ч. цикл зависимостей).
     */
    public function get(string $id): mixed;

    /**
     * Есть ли в контейнере запись для данного идентификатора?
     */
    public function has(string $id): bool;

    /**
     * Зарегистрировать сервис через фабрику (Composition Root, Статья 11.1).
     * Фабрика вызывается один раз при первом get() (singleton по умолчанию).
     *
     * @param callable(self):mixed $factory
     */
    public function set(string $id, callable $factory): void;

    /**
     * Зарегистрировать готовый экземпляр (instance).
     */
    public function instance(string $id, object $instance): void;

    /**
     * Зарегистрировать фабрику, вызываемую при КАЖДОМ get() (прототип/фабрика).
     *
     * @param callable(self):mixed $factory
     */
    public function factory(string $id, callable $factory): void;

    /**
     * Привязать контракт к реализации (class-map). Реализация резолвится автоматически.
     */
    public function bind(string $contract, string $concrete): void;

    /**
     * Удалить регистрацию и/или инстанс.
     */
    public function forget(string $id): void;
}
