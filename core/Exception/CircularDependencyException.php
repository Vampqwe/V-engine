<?php
declare(strict_types=1);

namespace Core\Exception;

/**
 * Обнаружен цикл при резолве зависимостей.
 * Сообщение всегда содержит полный путь цикла: A -> B -> A.
 */
class CircularDependencyException extends ContainerException
{
    /**
     * @param string[] $resolutionPath Цепочка резолва, начиная с повторившегося id.
     */
    public static function inPath(array $resolutionPath): self
    {
        return new self(sprintf(
            'Circular dependency detected: %s',
            implode(' -> ', $resolutionPath)
        ));
    }
}
