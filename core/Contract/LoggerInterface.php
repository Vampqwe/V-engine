<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт логирования (Статья 16.1). Приведён в соответствие Конституции:
 * добавлены critical() и сигнатура channel(): LoggerInterface.
 * Статья 31: запись только через каналы, минуя прямые вызовы ФС.
 */
interface LoggerInterface {
    public function channel(string $name): LoggerInterface;
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
}
