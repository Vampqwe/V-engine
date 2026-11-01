<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\EventBusInterface;
use Core\Contract\LoggerInterface;
use Core\Exception\EngineException;

/**
 * Шина событий (ШАГ 3, Статьи 17–18).
 *
 * Инварианты:
 *  - детерминированный порядок: сортировка по priority DESC, стабильно по
 *    порядку регистрации (PHP 8 sort stable);
 *  - изоляция слушателей: Throwable одного не срывает цепочку — он
 *    логируется (если передан Logger) и попадает в errors результата;
 *  - ядро регистрирует EventBus ДО модулей, но Logger доступен лениво
 *    (замыкание), чтобы порядок P0 (§8.5) не нарушался.
 */
final class EventBus implements EventBusInterface
{
    public const VERSION = '1.0.0';

    /** События жизненного цикла запроса (§17) — только для валидации имён. */
    public const KERNEL_EVENTS = [
        'onRequestStart',
        'onRouteResolved',
        'onBeforeRender',
        'onAfterRender',
        'onRequestEnd',
        'onError',
        'onFileLoad',
        'onFileSave',
        'onDirCreate',
        'health.check',
    ];

    /** @var array<string, array<int, array<int, callable>>> event => priority => index => listener */
    private array $listeners = [];

    /** @var array<string, int> счётчик индексов регистрации по событию */
    private array $counters = [];

    /** @var ?\Closure():?LoggerInterface ленивый доступ к логгеру */
    private ?\Closure $loggerFactory;

    /** @param ?\Closure(?LoggerInterface) $loggerFactory */
    public function __construct(?\Closure $loggerFactory = null)
    {
        $this->loggerFactory = $loggerFactory ?? static fn (): ?LoggerInterface => null;
    }

    public function listen(string $event, callable $listener, int $priority = 0): void
    {
        if ($event === '') {
            throw new EngineException('Event name cannot be empty.');
        }
        $index = $this->counters[$event] ?? 0;
        $this->counters[$event] = $index + 1;
        $this->listeners[$event][$priority][$index] = $listener;
    }

    public function removeListeners(string $event): void
    {
        unset($this->listeners[$event], $this->counters[$event]);
    }

    public function hasListeners(string $event): bool
    {
        return isset($this->listeners[$event]) && $this->listeners[$event] !== [];
    }

    /**
     * {@inheritDoc}
     */
    public function dispatch(string $event, array $payload = []): array
    {
        $results = [];
        $errors = [];

        if (!$this->hasListeners($event)) {
            return ['results' => $results, 'errors' => $errors];
        }

        $priorities = array_keys($this->listeners[$event]);
        rsort($priorities, SORT_NUMERIC);

        foreach ($priorities as $priority) {
            /** @var array<int, callable> $bucket Порядок ключей = порядок регистрации */
            $bucket = $this->listeners[$event][(int) $priority];
            foreach ($bucket as $index => $listener) {
                $key = $event . ':' . $priority . ':' . $index;
                try {
                    $results[$key] = $listener($payload);
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                    $this->logError($event, $e);
                }
            }
        }

        return ['results' => $results, 'errors' => $errors];
    }

    private function logError(string $event, \Throwable $e): void
    {
        $logger = ($this->loggerFactory)();
        if ($logger instanceof LoggerInterface) {
            $logger->error('eventbus.listener_failed', [
                'event'   => $event,
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }
}
