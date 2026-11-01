<?php
declare(strict_types=1);

namespace Core\Exception;

/**
 * Ошибки P1-модулей: Security / Session / Cookie / Database / View / Cache.
 */
class SecurityException extends EngineException
{
}

class DatabaseNotConfiguredException extends EngineException
{
}
