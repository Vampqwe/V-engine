<?php
declare(strict_types=1);

namespace Tests\Unit\Container;

use Core\Contract\LoggerInterface;

/**
 * Заглушка логгера для тестов контейнера.
 */
final class LoggerStub implements LoggerInterface
{
    public function channel(string $name): LoggerInterface
    {
        return $this;
    }

    public function info(string $message, array $context = []): void
    {
    }

    public function error(string $message, array $context = []): void
    {
    }

    public function warning(string $message, array $context = []): void
    {
    }

    public function critical(string $message, array $context = []): void
    {
    }
}
