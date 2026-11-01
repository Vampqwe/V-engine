<?php
declare(strict_types=1);

namespace Core\Exception;

use RuntimeException;

/**
 * Запрошенный сервис не зарегистрирован и не является существующим классом.
 */
class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
