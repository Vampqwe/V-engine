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
