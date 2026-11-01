<?php
declare(strict_types=1);

namespace Core\Exception;

/**
 * Операция файлового хранилища не может быть выполнена
 * (выход за root, недоступность диска, отказ записи).
 */
final class FileStorageException extends ContainerException
{
}
