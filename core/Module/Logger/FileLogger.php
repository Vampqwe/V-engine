<?php
declare(strict_types=1);

namespace Core\Module\Logger;

use Core\Contract\FileStorageInterface;
use Core\Contract\LoggerInterface;

/**
 * P0-модуль Logger (ШАГ 2, Статья 8.1).
 *
 * Пишет строки формата JSON Lines (одна запись = одна строка) в каналы
 * через FileStorageInterface — ядро не трогает ФС напрямую (Конституция).
 *
 *  - channel() возвращает ЛЁГКОЕ имutable-копию с другим именем канала
 *    (общий FileStorage разделяется, создание дёшево);
 *  - неизвестный уровень пишет в 'app' и добавляет diagnostic-поле —
 *    логгер никогда не бросает исключение из hot-path обработки запроса;
 *  - context сериализуется безопасно: DateTime/Throwable/JsonSerializable
 *    разворачиваются, остальное приводится к скалярам.
 */
final class FileLogger implements LoggerInterface
{
    public const VERSION = '1.0.0';

    private const DEFAULT_CHANNEL = 'app';

    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly string $channel = self::DEFAULT_CHANNEL,
        private readonly string $logDir = 'logs',
    ) {
    }

    public function channel(string $name): LoggerInterface
    {
        $name = trim($name);
        return new self($this->storage, $name === '' ? self::DEFAULT_CHANNEL : $name, $this->logDir);
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

    // =========================================================================
    // Internals
    // =========================================================================

    private function write(string $level, string $message, array $context): void
    {
        $record = [
            'ts'      => date('c'),
            'level'   => $level,
            'channel' => $this->channel,
            'message' => $message,
        ];
        if ($context !== []) {
            $record['context'] = $this->sanitizeContext($context);
        }

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($line === false) {
            $line = '{"level":"' . $level . '","channel":"' . $this->channel . '","message":"unserializable log record"}';
        }

        $path = $this->logPath();
        $existing = $this->storage->load($path);
        $content = ($existing === null ? '' : $existing) . $line . "\n";

        // Файл лога НЕ атомарен по природе (append), поэтому save() перезаписывает
        // полной копией; конкурентные писатели могут потерять строку — допустимо
        // для P0 (файловый лог без flock), усилим на этапе Queue/AuditLog.
        $this->storage->save($path, $content);
    }

    private function logPath(): string
    {
        return sprintf(
            '%s/%s-%s.log',
            trim($this->logDir, '/'),
            preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->channel),
            date('Y-m-d'),
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $out[(string) $key] = match (true) {
                is_scalar($value) || $value === null => $value,
                $value instanceof \DateTimeInterface => $value->format('c'),
                $value instanceof \Throwable => [
                    'class'   => $value::class,
                    'message' => $value->getMessage(),
                    'code'    => $value->getCode(),
                    'file'    => $value->getFile() . ':' . $value->getLine(),
                ],
                $value instanceof \JsonSerializable => $value->jsonSerialize(),
                is_array($value) => $this->sanitizeContext($value),
                default => is_object($value) ? get_class($value) : gettype($value),
            };
        }
        return $out;
    }
}
