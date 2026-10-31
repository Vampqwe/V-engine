<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\EventBusInterface;

/**
 * P0: Шина событий (Раздел IV Конституции).
 * Статья 17: фиксированный набор хуков ядра. dispatch() — внутренний API ядра,
 * пользователю доступен только listen() (Статьи 18, 23).
 */
final class EventBus implements EventBusInterface
{
    /** Разрешённые события ядра (Статья 17). */
    private const KERNEL_EVENTS = [
        'onRequestStart', 'onRouteResolved', 'onBeforeRender', 'onAfterRender',
        'onRequestEnd', 'onError',
        'onFileLoad', 'onFileSave', 'onDirCreate',
        'health.check',
    ];

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        if (!in_array($event, self::KERNEL_EVENTS, true)) {
            throw new \InvalidArgumentException("Событие не объявлено в Статье 17: {$event}");
        }
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, mixed $payload = null): mixed
    {
        $result = $payload;
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $returned = $listener($result);
            if ($returned !== null) {
                $result = $returned;
            }
        }
        return $result;
    }

    public function getListeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }
}
