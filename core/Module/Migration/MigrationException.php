<?php
declare(strict_types=1);

namespace Core\Module\Migration;

/**
 * Ошибка системы миграций (неверный файл, отсутствие down-скрипта и т.п.).
 */
final class MigrationException extends \RuntimeException
{
}
