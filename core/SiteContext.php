<?php
declare(strict_types=1);

namespace Core;

use Core\Contract\SiteContextInterface;
use Core\Exception\ContainerException;

/**
 * Контекст сайта (многосайтовость, §10 + принятые решения).
 *
 * Жёсткая привязка домена к папке сайта:
 *   SITE_URL=https://mysite.com  =>  SITE_DIR=<ENGINE_ROOT>/sites/mysite.com
 *
 * Единая точка входа public/index.php; APP_ROOT = __DIR__ движка.
 * Домен берётся из HTTP_HOST (веб) или из env-переменной SITE_DOMAIN (CLI/тесты).
 */
final class SiteContext implements SiteContextInterface
{
    public const VERSION = '1.0.0';

    private function __construct(
        private readonly string $engineRoot,
        private readonly string $domain,
        private readonly string $baseFolder,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * Построить контекст по запросу/окружению.
     *
     * @param array<string,string> $server $_SERVER-подобный массив
     * @param array<string,string> $env    переменные окружения (SITE_URL, SITE_DOMAIN...)
     */
    public static function detect(string $engineRoot, array $server = [], array $env = []): self
    {
        $engineRoot = rtrim(realpath($engineRoot) ?: $engineRoot, '/');

        // 1. Явный SITE_URL из конфигурации окружения имеет приоритет.
        $siteUrl = $env['SITE_URL'] ?? '';
        if ($siteUrl !== '') {
            $domain = self::hostFromUrl($siteUrl);
        } else {
            // 2. Домен запроса (web), иначе SITE_DOMAIN (CLI).
            $domain = strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));
            if ($domain === '') {
                $domain = strtolower(trim((string) ($env['SITE_DOMAIN'] ?? '')));
            }
            if ($domain === '') {
                throw new ContainerException('Cannot determine site: neither SITE_URL nor HTTP_HOST/SITE_DOMAIN provided.');
            }
            // порт из "example.com:8080" отбрасываем — папка привязана к чистому хосту
            $colon = strpos($domain, ':');
            if ($colon !== false) {
                $domain = substr($domain, 0, $colon);
            }
        }

        if (preg_match('/^[a-z0-9.-]+$/', $domain) !== 1) {
            throw new ContainerException(sprintf('Invalid site domain "%s".', $domain));
        }

        $baseFolder = $engineRoot . '/sites/' . $domain;
        if (!is_dir($baseFolder)) {
            throw new ContainerException(sprintf('Site folder "%s" does not exist for domain "%s".', $baseFolder, $domain));
        }

        // Схема: явный SITE_URL > эвристика по хосту. Для localhost/127.x/тестовых
        // .test/.local доменов — http, иначе сессийные cookie с флагом Secure
        // не устанавливаются браузером по http:// и CSRF-цикл админки ломается.
        if (filter_var($siteUrl, FILTER_VALIDATE_URL) !== false) {
            $scheme = parse_url($siteUrl, PHP_URL_SCHEME) ?: 'https';
        } elseif (preg_match('/^(localhost|127\.|\d+\.\d+\.\d+\.\d+|.*\.(test|local|localhost)$)/', $domain) === 1) {
            $scheme = 'http';
        } else {
            $scheme = 'https';
        }
        return new self($engineRoot, $domain, $baseFolder, $scheme . '://' . $domain);
    }

    private static function hostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new ContainerException(sprintf('SITE_URL "%s" has no host component.', $url));
        }
        return strtolower($host);
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getBaseFolder(): string
    {
        return $this->baseFolder;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getEnvPath(): string
    {
        return $this->baseFolder . '/.env';
    }

    public function getConfigPath(): string
    {
        return $this->baseFolder . '/config';
    }
}
