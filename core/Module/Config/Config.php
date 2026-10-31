<?php
declare(strict_types=1);

namespace Core\Module\Config;

use Core\Contract\ConfigInterface;
use Core\Contract\FileStorageInterface;
use Core\Contract\SiteContextInterface;

/**
 * P0-модуль Config (Статьи 8.1, 8.5, 8.7, 30).
 * Читает конфигурационные файлы движка ENGINE_BASE_PATH/config/*.php и .env сайта
 * (SITE_BASE_FOLDER/.env — Статья 30). Точки доступа задаёт SiteContext.
 */
final class Config implements ConfigInterface
{
    /** @var array<string, array<string, mixed>> name -> data */
    private array $files = [];

    /** @var array<string, string> */
    private array $env = [];

    public function __construct(
        private readonly FileStorageInterface $storage,
        SiteContextInterface $context,
    ) {
        // Конфиги движка — привилегия ядра (Статья 23 ограничивает пользователя, не ядро).
        $this->loadEngineConfigs($context);
        // .env сайта — строго через FileStorage (песочница public/{site}/).
        $this->loadEnv();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$file, $path] = array_pad(explode('.', $key, 2), 2, '');
        if (!isset($this->files[$file])) {
            return $default;
        }
        if ($path === '') {
            return $this->files[$file];
        }
        $cursor = $this->files[$file];
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    public function has(string $key): bool
    {
        $sentinel = new \stdClass();
        return $this->get($key, $sentinel) !== $sentinel;
    }

    public function all(string $file): array
    {
        return $this->files[$file] ?? [];
    }

    public function env(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->env)) {
            return $this->env[$key];
        }
        $fromProcess = getenv($key);
        return $fromProcess === false ? $default : $fromProcess;
    }

    private function loadEngineConfigs(SiteContextInterface $context): void
    {
        $engineRoot = rtrim($context->getEngineFolder(), '/');
        foreach (glob($engineRoot . '/config/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $data = require $file;
            $this->files[$name] = is_array($data) ? $data : [];
        }
    }

    /** Парсит .env сайта: KEY=value, комментарии #, кавычки. */
    private function loadEnv(): void
    {
        $raw = $this->storage->load('.env');
        if ($raw === null) {
            return;
        }
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if (strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                 || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            } else {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }
            if ($key !== '') {
                $this->env[$key] = $value;
            }
        }
    }
}
