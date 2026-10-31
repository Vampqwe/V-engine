<?php
declare(strict_types=1);

// ==============================================================================
// 1. ЕДИНАЯ ТОЧКА ОТЧЕТА
// ==============================================================================
define('ENGINE_BASE_PATH', dirname(__DIR__));

// ==============================================================================
// 2. АВАРИЙНЫЙ ЛЮК (500 Hardcode) - Статья 31 Конституции
// Срабатывает, если ядро упало ДО того, как успело инициализироваться
// ==============================================================================
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        // Читаем статический, безопасный HTML-файл вне досягаемости веб-сервера
        $fallback = ENGINE_BASE_PATH . '/core/Fallback/500.html';
        if (file_exists($fallback)) {
            readfile($fallback);
        } else {
            echo '<h1>500 Internal Server Error</h1>';
        }
        exit(1);
    }
});

// ==============================================================================
// 3. ИНИЦИАЛИЗАЦИЯ ЯДРА
// ==============================================================================
try {
    require_once ENGINE_BASE_PATH . '/core/Kernel.php';
    
    // Здесь будет дальнейший бутстрап:
    // $kernel = new \Core\Kernel();
    // $kernel->boot();
    // $kernel->run();
    
} catch (Throwable $e) {
    // Если ядро загрузилось, но упало при инициализации, 
    // в будущем этим займется ExceptionHandler ядра.
    // Пока используем запасной вариант.
    http_response_code(500);
    $fallback = ENGINE_BASE_PATH . '/core/Fallback/500.html';
    if (file_exists($fallback)) {
        readfile($fallback);
    }
    exit(1);
}