<?php
declare(strict_types=1);

namespace Core\Exception;

/**
 * Ошибки ШАГА 3: EventBus / Registry / Router (наследник общего
 * ContainerException не подходит — это инфраструктура запроса, а не DI).
 */
class EngineException extends \RuntimeException
{
}
