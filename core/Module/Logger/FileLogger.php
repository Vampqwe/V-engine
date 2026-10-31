<?php
declare(strict_types=1);

namespace Core\Module\Logger;

use Core\Contract\FileStorageInterface;
use Core\Contract\LoggerInterface;

/**
 * P0-модуль Logger — логирование через изолированные каналы (Статья 31).
 * Формат: storage/logs/{date}/{channel}.log. Запись инкапсулирована в FileStorage
 * (атомарность LOCK_EX), ротация по размеру. Это единственный легальный писатель логов.
 */
final class FileLogger implements LoggerInterface
{
    /** @param string[] $allowedChannels Белый список каналов из config/logger.php */
    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly array $allowedChannels = ['app', 'error', 'config', 'installer', 'security', 'queue'],
        private readonly string $channel = 'app',
        private readonly string $logDir = 'storage/logs',
        private readonly int $maxFileSize = 1_048_576,
    ) {
    }

    public function channel(string $name): LoggerInterface
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $name)) {
            throw new \InvalidArgumentException('Недопустимое имя канала: ' . $name);
        }
        if (!in_array($name, $this->allowedChannels, true)) {
            throw new \InvalidArgumentException(
                "Канал '{$name}' не объявлен в config/logger.php (channels)"
            );
        }
        return new self(
            $this->storage,
            $this->allowedChannels,
            $name,
            $this->logDir,
            $this->maxFileSize,
        );
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->write('CRITICAL', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $path = $this->channelPath();
        $line = sprintf(
            "[%s] %s: %s%s\n",
            (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Инкрементальная запись без чтения всего файла: size() дешёвый, append атомарен.
        $size = $this->storage->size($path);
        if ($size !== null && $size + strlen($line) > $this->maxFileSize) {
            $rotated = $this->logDir . '/rotated/' . $this->channel . '-'
                . (new \DateTimeImmutable('now'))->format('Ymd-His') . '.log';
            $current = $this->storage->load($path) ?? '';
            $this->storage->save($rotated, $current);
        }
        $this->storage->append($path, $line);
    }

    private function channelPath(): string
    {
        return $this->logDir . '/' . (new \DateTimeImmutable('now'))->format('Y-m-d')
            . '/' . $this->channel . '.log';
    }
}
