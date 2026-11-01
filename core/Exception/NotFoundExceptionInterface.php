<?php
declare(strict_types=1);

namespace Core\Exception;

use Throwable;

/**
 * Маркерный контракт: идентификатор запрошен, но не найден / не может быть создан.
 * PSR-11 совместимость на уровне поведения.
 */
interface NotFoundExceptionInterface extends \Throwable
{
}
