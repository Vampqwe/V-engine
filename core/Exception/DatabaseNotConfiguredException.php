<?php
declare(strict_types=1);

namespace Core\Exception;

/**
 * База данных не сконфигурирована (§7 деградация): понятное исключение
 * вместо тихого отказа или fatal error.
 */
class DatabaseNotConfiguredException extends EngineException
{
}
