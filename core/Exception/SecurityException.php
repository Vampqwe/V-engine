<?php
declare(strict_types=1);

namespace Core\Exception;

/**
 * Ошибка модуля Security (P1, §8.2.1): слабый секрет, невалидный CSRF-токен,
 * недопустимые параметры генерации.
 */
class SecurityException extends EngineException
{
}
