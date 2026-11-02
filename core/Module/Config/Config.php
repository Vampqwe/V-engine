<?php
declare(strict_types=1);

namespace Core\Module\Config;

use Core\Contract\ConfigInterface;
use Core\Exception\ConfigException;

/**
 * P0-модуль Config (ШАГ 2, ERRATA-001, Статья 8.1).
 *
 * Слои конфигурации (поздний переопределяет ранний):
 *   1. config-файлы секций (PHP-массивы) — базовые значения движка и сайта;
 *   2. .env сайта — средо-зависимые/секретные значения (SITE_URL, DB_*, ...);
 *   3. runtime set() — временные переопределения (тесты, boot-коррекция).
 *
 * Ключи «точечные»: 'logger.path', 'app.debug'. Секция = имя файла без .php.
 * Скалярные ENV-значения нормализуются: "true"/"false"/"null"/числа => типизированные.
 */
final class Config implements ConfigInterface
{
    public const VERSION = '1.0.0';

    /**
     * Префиксы ENV-имён, допустимые как конфигурация приложения
     * (см. isAppEnvName()). Собраны по реальным .env/.env.example движка:
     * APP_*, CACHE_*, CORE_*, DB_*, SESSION_*, SITE_*, MAIL_*, KERNEL_*, TRUSTED_*.
     */
    private const ENV_PREFIXES = [
        'APP_',
        'CACHE_',
        'CORE_',
        'DB_',
        'KERNEL_',
        'LOG_',
        'MAIL_',
        'QUEUE_',
        'SESSION_',
        'SITE_',
        'STORAGE_',
        'TRUSTED_',
        'UPLOAD_',
        'VIEW_',
    ];

    /** Точные имена без префикса-группы. */
    private const ENV_EXACT_NAMES = [
        'ENV',
        'DEBUG',
    ];

    /** @var array<string, array<string, mixed>> section => data */
    private array $sections = [];

    /** @var array<string, mixed> точечный ключ => значение (runtime-переопределения) */
    private array $overrides = [];

    /**
     * @param string $configDir Каталог с *.php-секциями (может не существовать — тогда пусто).
     * @param ?string $envPath  Путь к .env (null — без env-слоя).
     */
    public function __construct(
        private readonly string $configDir = '',
        private readonly ?string $envPath = null,
    ) {
    }

    /** Загрузить все PHP-секции из configDir и применить env-слой. */
    public function load(): self
    {
        if ($this->configDir !== '' && is_dir($this->configDir)) {
            foreach (glob($this->configDir . '/*.php') ?: [] as $file) {
                $section = basename($file, '.php');
                $data = $this->requireSection($file);
                $existing = $this->sections[$section] ?? [];
                $this->sections[$section] = array_replace_recursive($existing, $data);
            }
        }
        // Слой 1: переменные окружения процесса (реальный ENV веб-сервера,
        // корневой .env движка, переданный через public/index.php). Значимые
        // (непустые) значения НЕ перекрываются файлом сайта — иначе APP_SECRET
        // из process-env игнорировался бы и boot падал с SecurityException
        // («APP_SECRET is missing»), что на хостинге выглядит как ошибка ядра.
        //
        // Белый список префиксов: в процессном окружении живёт десятки
        // служебных переменных (PATH, HOME, TERM, TMUX_PANE, SHELL levels…),
        // которые не являются конфигурацией приложения. Маппинг «всё, что
        // похоже на UPPER_SNAKE» в конфиг превращал бы их в секции ('shell',
        // 'term', 'pwd', 'home' …) и протекал бы в all()/экспорт
        // DataPortability. Конфигурация движка и сайтов использует только
        // префиксы ниже (+ точечные ключи без подчёркиваний).
        $processVars = [];
        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            foreach ((array) $source as $name => $raw) {
                if (!is_string($name) || !self::isAppEnvName($name)) {
                    continue; // отсеем HTTP_*, REQUEST_*, PATH/HOME/TMUX_* и прочий мусор
                }
                $value = is_scalar($raw) ? trim((string) $raw) : '';
                if ($value !== '') {
                    $processVars[$name] = $value;
                }
            }
        }
        $this->applyEnv($processVars);

        // Слой 2: sites/<домен>/.env — заполняет только те ключи, которых нет
        // в процессном окружении, либо их значение пустое (например APP_SECRET=
        // в закоммиченном шаблоне).
        if ($this->envPath !== null && is_file($this->envPath)) {
            $siteVars = [];
            foreach ($this->parseEnvFile($this->envPath) as $name => $value) {
                $normalized = strtolower(str_replace('_', '.', (string) $name));
                if (array_key_exists($normalized, $this->overrides) && $this->overrides[$normalized] !== '') {
                    continue; // процессный ENV важнее файла сайта
                }
                $siteVars[$name] = $value;
            }
            $this->applyEnv($siteVars);
        }
        return $this;
    }

    /** Зарегистрировать/заменить секцию целиком из массива (boot-инъекции ядра, тесты). */
    public function replace(string $section, array $data): void
    {
        $this->sections[$this->normalizeName($section)] = $data;
    }

    /** Добавить значения из assoc-массива (например, переменные окружения процесса). */
    public function applyEnv(array $vars): void
    {
        foreach ($vars as $name => $raw) {
            $key = strtolower(str_replace('_', '.', (string) $name));
            $this->overrides[$key] = is_string($raw) ? self::castEnvValue($raw) : $raw;
        }
    }

    // =========================================================================
    // ConfigInterface
    // =========================================================================

    public function get(string $key, mixed $default = null): mixed
    {
        $key = trim($key);
        if ($key === '') {
            return $default;
        }
        if (array_key_exists($key, $this->overrides)) {
            return $this->overrides[$key];
        }

        [$section, $path] = $this->splitKey($key);
        $node = $this->sections[$section] ?? null;
        if (!is_array($node)) {
            return $default;
        }
        if ($path === null) {
            return $node;
        }
        foreach (explode('.', $path) as $segment) {
            if (is_array($node) && array_key_exists($segment, $node)) {
                $node = $node[$segment];
            } else {
                return $default;
            }
        }
        return $node;
    }

    public function set(string $key, mixed $value): void
    {
        $key = trim($key);
        if ($key === '') {
            throw new ConfigException('Config key must not be empty.');
        }
        $this->overrides[$key] = $value;
    }

    public function has(string $key): bool
    {
        $sentinel = "\0__missing__\0";
        return $this->get($key, $sentinel) !== $sentinel;
    }

    /** Имена всех загруженных секций (для диагностики, без раскрытия значений). */
    public function sectionNames(): array
    {
        return array_keys($this->sections);
    }

    public function all(?string $section = null): array
    {
        if ($section === null) {
            $merged = [];
            foreach ($this->sections as $name => $data) {
                $merged[$name] = $data;
            }
            // Оверлеи раскладываем по секциям для единообразия all().
            foreach ($this->overrides as $key => $value) {
                [$sec, $path] = $this->splitKey($key);
                if ($path === null) {
                    $merged[$sec] = $value;
                    continue;
                }
                $ref = &$merged[$sec];
                if (!is_array($ref)) {
                    $ref = [];
                }
                foreach (explode('.', $path) as $i => $segment) {
                    if ($i === count(explode('.', $path)) - 1) {
                        $ref[$segment] = $value;
                    } else {
                        if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                            $ref[$segment] = [];
                        }
                        $ref = &$ref[$segment];
                    }
                }
                unset($ref);
            }
            return $merged;
        }
        return (array) $this->get($section, []);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @return array<string, mixed> */
    private function requireSection(string $file): array
    {
        $data = require $file;
        if (!is_array($data)) {
            throw new ConfigException(sprintf('Config file "%s" must return an array.', $file));
        }
        return $data;
    }

    /**
     * Парсер .env: KEY=value, комментарии #, кавычки, экспорт-префикс.
     * @return array<string, string>
     */
    public static function parseEnvFile(string $path): array
    {
        $out = [];
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new ConfigException(sprintf('Cannot read env file "%s".', $path));
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (str_starts_with($line, 'export ')) {
                    $line = substr($line, 7);
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $name = rtrim(substr($line, 0, $pos));
                $value = ltrim(substr($line, $pos + 1));
                if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name) !== 1) {
                    continue; // молча пропускаем мусорные строки
                }
                $value = self::unquote($value);
                $out[$name] = $value;
            }
        } finally {
            fclose($handle);
        }
        return $out;
    }

    /**
     * Белый список имён ENV, которые считаются конфигурацией приложения.
     *
     * В процессном окружении (getenv()/$_ENV/$_SERVER) живёт множество
     * служебных переменных ОС и веб-сервера (PATH, HOME, TERM, TMUX_PANE,
     * HTTP_*, REQUEST_*, REDIS_* …). Маппинг «всего похожего на UPPER_SNAKE»
     * в конфиг превращал бы их в секции ('shell', 'term', 'pwd' …) и
     * протекал бы в all()/экспорт DataPortability. Конфигурация движка и
     * сайтов использует префиксы ниже; точечные ключи (app.secret) — тоже.
     *
     * Файл .env сайта проходит через parseEnvFile() и этим фильтром НЕ
     * режется: ограничение касается только процессного окружения.
     */
    public static function isAppEnvName(string $name): bool
    {
        // Точечный стиль: site.url, cache.ttl — всегда конфигурация.
        if (str_contains($name, '.')) {
            return (bool) preg_match('/^[a-z0-9_.-]+$/i', $name);
        }
        // UPPER_SNAKE с разрешённым префиксом (APP_, DB_, SITE_, CORE_ ...).
        foreach (self::ENV_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        // Единичные точные имена без общего префикса.
        return in_array($name, self::ENV_EXACT_NAMES, true);
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $inner = substr($value, 1, -1);
                if ($first === '"') {
                    $inner = str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], $inner);
                }
                return $inner;
            }
        }
        // Инлайн-комментарий вне кавычек: "VALUE # comment" => "VALUE"
        $hash = strpos($value, ' #');
        if ($hash !== false) {
            $value = rtrim(substr($value, 0, $hash));
        }
        return $value;
    }

    /** Нормализация скалярных строк ENV к типам PHP. */
    public static function castEnvValue(string $value): mixed
    {
        return match (strtolower($value)) {
            'true'           => true,
            'false'          => false,
            'null', '(null)' => null,
            'empty', ''      => '',
            default          => match (true) {
                preg_match('/^-?\d+$/', $value) === 1 && (int) $value >= PHP_INT_MIN && (int) $value <= PHP_INT_MAX => (int) $value,
                preg_match('/^-?\d*\.\d+(e[-+]?\d+)?$/i', $value) === 1 => (float) $value,
                default => $value,
            },
        };
    }

    /** @return array{0:string, 1:?string} [section, dotted-path|null] */
    private function splitKey(string $key): array
    {
        $pos = strpos($key, '.');
        if ($pos === false) {
            return [$this->normalizeName($key), null];
        }
        return [$this->normalizeName(substr($key, 0, $pos)), ltrim(substr($key, $pos + 1), '.')];
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/[^a-z0-9_.-]/i', '_', $name) ?? $name;
    }
}
