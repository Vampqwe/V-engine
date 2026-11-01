<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт логгера (Статья 16.1 Конституции).
 * Расширен critical() согласно тексту Конституции (в исходном каркасе отсутствовал).
 */
interface LoggerInterface
{
    public function channel(string $name): LoggerInterface;

    public function info(string $message, array $context = []): void;

    public function warning(string $message, array $context = []): void;

    public function error(string $message, array $context = []): void;

    public function critical(string $message, array $context = []): void;
}