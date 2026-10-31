<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\SiteContextInterface;

/**
 * Контекст текущего сайта (Статья 16.10). Путь к папке сайта передаётся
 * ядром из точки входа (public/index.php), конфигурируется в env-файле движка
 * ENGINE_BASE_PATH/.env: SITE_DOMAIN, SITE_BASE_URL.
 */
final class SiteContext implements SiteContextInterface
{
    public function __construct(
        private readonly string $engineBasePath,
        private readonly string $siteDomain,
        private readonly string $baseUrl,
    ) {
        if (!preg_match('/^[a-z0-9.\-]+$/i', $siteDomain)) {
            throw new \InvalidArgumentException('Некорректный домен сайта: ' . $siteDomain);
        }
    }

    /** Загружает контекст из .env движка (SITE_DOMAIN, SITE_BASE_URL). */
    public static function fromEngineEnv(string $engineBasePath): self
    {
        $env = self::readEnvFile($engineBasePath . '/.env');
        $domain = $env['SITE_DOMAIN'] ?? '';
        if ($domain === '') {
            throw new \RuntimeException(
                'SITE_DOMAIN не задан в ' . $engineBasePath . '/.env — ядро не знает, какой сайт обслуживать'
            );
        }
        return new self(
            $engineBasePath,
            $domain,
            $env['SITE_BASE_URL'] ?? ('/' . $domain),
        );
    }

    public function getBaseFolder(): string
    {
        return $this->engineBasePath . '/public/' . $this->siteDomain;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getEnvPath(): string
    {
        return $this->getBaseFolder() . '/.env';
    }

    public function getConfigPath(): string
    {
        return $this->engineBasePath . '/config';
    }

    public function getEngineFolder(): string
    {
        return $this->engineBasePath;
    }

    /** @return array<string,string> Простейший парсер .env без side-effect на getenv(). */
    private static function readEnvFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $result = [];
        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
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
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
