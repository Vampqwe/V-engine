<?php
declare(strict_types=1);

namespace Core\Module\AuditLog;

use Core\Contract\AuditLogInterface;
use Core\Contract\DatabaseInterface;
use Core\Contract\FileStorageInterface;

/**
 * Журнал аудита (P2). Двойное хранилище с деградацией:
 *  - БД доступна → таблица 'audit_log' (insert через DatabaseInterface);
 *  - иначе → append-only файл audit/audit-{Y-m-d}.log в jailed-root,
 *    одна JSON-запись на строку.
 *
 * Append-only: интерфейс не предоставляет update/delete (§ неизменяемость).
 */
final class FileAuditLog implements AuditLogInterface
{
    public const VERSION = '1.0.0';

    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly ?DatabaseInterface $database = null,
        private readonly ?string $ip = null,
    ) {
    }

    public function log(string $action, array $context = [], ?int $userId = null): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.\-]{1,64}$/', $action)) {
            throw new \InvalidArgumentException('Invalid audit action name.');
        }
        $entry = [
            'ts' => time(),
            'action' => $action,
            'user' => $userId,
            'ip' => $this->ip,
            'context' => $context,
        ];

        if ($this->database?->isAvailable()) {
            try {
                $this->database->insert('audit_log', [
                    'created_at' => date('Y-m-d H:i:s', $entry['ts']),
                    'action' => $entry['action'],
                    'user_id' => $entry['user'],
                    'ip' => $entry['ip'],
                    'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                ]);
                return;
            } catch (\Throwable) {
                // БД отвалилась посреди запроса — падаем в файл, аудит не теряется.
            }
        }

        $path = 'audit/audit-' . date('Y-m-d', $entry['ts']) . '.log';
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $existing = $this->storage->load($path) ?? '';
        $this->storage->save($path, $existing . $line);
    }

    public function recent(int $limit = 50): array
    {
        if ($this->database?->isAvailable()) {
            try {
                $rows = $this->database->findAll('audit_log');
                usort($rows, static fn (array $a, array $b): int => strcmp(
                    (string) ($b['created_at'] ?? ''),
                    (string) ($a['created_at'] ?? ''),
                ));
                return array_map(static fn (array $r): array => [
                    'ts' => strtotime((string) ($r['created_at'] ?? 'now')) ?: time(),
                    'action' => (string) ($r['action'] ?? ''),
                    'user' => isset($r['user_id']) ? (int) $r['user_id'] : null,
                    'ip' => $r['ip'] ?? null,
                    'context' => json_decode((string) ($r['context'] ?? '{}'), true) ?: [],
                ], array_slice($rows, 0, max(1, $limit)));
            } catch (\Throwable) {
                // Деградация к файловому чтению.
            }
        }

        $files = $this->storage->list('audit/*.log');
        rsort($files); // свежие даты вперёд
        $out = [];
        foreach ($files as $file) {
            $raw = $this->storage->load((string) $file);
            if ($raw === null) {
                continue;
            }
            $lines = array_filter(explode("\n", trim($raw)));
            foreach (array_reverse($lines) as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry) && isset($entry['ts'], $entry['action'])) {
                    $out[] = $entry;
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }
        return $out;
    }
}
