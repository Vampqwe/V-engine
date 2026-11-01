<?php
declare(strict_types=1);

/**
 * Хелперы форматирования для шаблонов админ-панели (путь ядра).
 * Загружаются каждым шаблоном панели через include (файл без вывода).
 */

if (!function_exists('lex_adm_bytes')) {
    /** Человекочитаемый размер байт. */
    function lex_adm_bytes(int|float|false|null $bytes): string
    {
        if ($bytes === false || $bytes === null) {
            return '—';
        }
        $b = (float) $bytes;
        foreach (['б', 'КБ', 'МБ', 'ГБ', 'ТБ'] as $unit) {
            if ($b < 1024) {
                return ($b >= 10 || $b === floor($b) ? number_format($b, 0, ',', ' ') : number_format($b, 1, ',', ' ')) . ' ' . $unit;
            }
            $b /= 1024;
        }
        return number_format($b, 2, ',', ' ') . ' ПБ';
    }
}

if (!function_exists('lex_adm_pct')) {
    /** Доля использованного 0..100 по free/total. */
    function lex_adm_pct(float|false|null $free, float|false|null $total): float
    {
        if ($free === false || $total === false || $total <= 0) {
            return 0.0;
        }
        return round((1 - $free / $total) * 100, 1);
    }
}

if (!function_exists('lex_adm_health_class')) {
    /** CSS-класс статуса Doctor. */
    function lex_adm_health_class(string $status): string
    {
        return match ($status) {
            'ok'       => 'ok',
            'warning'  => 'warn',
            'fail'     => 'fail',
            default    => 'muted',
        };
    }
}

if (!function_exists('lex_adm_icon')) {
    /** Иконка статуса. */
    function lex_adm_icon(string $class): string
    {
        return match ($class) {
            'ok'   => '●',
            'warn' => '▲',
            'fail' => '✕',
            default => '○',
        };
    }
}
/** CSS-переменная цвета по статусу health (белый список — safe для jail-детектора §40). */
function lex_adm_css_var(string $status): string
{
    return match ($status) {
        'ok' => 'success',
        'fail' => 'danger',
        default => 'attention',
    };
}
/** Строгое целое как строка — safe для вывода через $esc(). */
function lex_adm_int(mixed $v): string
{
    return (string) (int) $v;
}
/** CSS-класс бейджа по HTTP-методу маршрута (белый список). */
function lex_adm_method_class(string $method): string
{
    return match (strtoupper($method)) {
        'GET' => 'info',
        'POST' => 'done',
        'PUT', 'PATCH' => 'attention',
        'DELETE' => 'fail',
        default => 'muted',
    };
}

/** CSS-класс полосы заполненности диска по проценту (белый список). */
function lex_adm_bar_class(float $pct): string
{
    return $pct > 90 ? 'fail' : ($pct > 75 ? 'warn' : 'ok');
}

/** Бейдж статуса наличия .env файла сайта. */
function lex_adm_env_badge(string $envInfo): string
{
    return str_contains($envInfo, 'ОТСУТ') ? 'fail' : 'ok';
}

/** Load average: null -> «—», иначе форматированная строка (safe для $esc). */
function lex_adm_load_str(mixed $load): string
{
    if ($load === null) {
        return '—';
    }
    return lex_adm_load((array) $load);
}
/** Бейдж «ok|fail» по наличию подстроки ОТСУТ в описании (env/config статусы). */
function lex_adm_presence_badge(string $info): string
{
    return str_contains($info, 'ОТСУТ') ? 'fail' : 'ok';
}

/** Бейдж display_errors: «1» — плохо (warn), иначе ok. */
function lex_adm_flag_badge(mixed $value): string
{
    return strtolower((string) $value) === '1' ? 'warn' : 'ok';
}
/** Бейдж по булеву флагу: true -> ok, false -> fail. */
function lex_adm_bool_badge(mixed $flag): string
{
    return $flag ? 'ok' : 'fail';
}
