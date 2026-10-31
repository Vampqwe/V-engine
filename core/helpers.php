<?php
declare(strict_types=1);

// ==============================================================================
// Глобальные хелперы ядра (Статья 10: единственное исключение из «только ООП»).
// ==============================================================================

if (!function_exists('e')) {
    /**
     * Безопасный вывод в шаблонах (Статьи 4, 35). Единственный легальный способ
     * экранирования — пользователь НЕ вызывает htmlspecialchars сам (Статья 22.5).
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_path')) {
    /** Абсолютный путь от корня движка (только для ядра и bootstrap). */
    function base_path(string $relative = ''): string
    {
        return rtrim(ENGINE_BASE_PATH . '/' . ltrim($relative, '/'), '/');
    }
}
