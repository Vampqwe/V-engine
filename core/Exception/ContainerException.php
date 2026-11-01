<?php
declare(strict_types=1);

namespace Core\Exception;

use RuntimeException;

/**
 * Нарушение контракта ContainerInterface.
 * Базовое исключение контейнера зависимостей (PSR-11-совместимое поведение,
 * без внешнего пакета — согласно запрету на сторонние production-зависимости).
 */
class ContainerException extends RuntimeException
{
}
