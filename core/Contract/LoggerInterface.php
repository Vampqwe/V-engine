<?php
declare(strict_types=1);

namespace Core\Contract;

interface LoggerInterface {
    public function channel(string $name): LoggerInterface;
    public function info(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
}