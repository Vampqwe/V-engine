<?php
declare(strict_types=1);

namespace Core\Exception;

/**
 * Ошибка загрузки файла: нарушение лимита размера, запрещённый тип,
 * сбой перемещения из временного хранилища, некорректная директория.
 */
final class UploadException extends EngineException
{
}
