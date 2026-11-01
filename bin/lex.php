#!/usr/bin/env php
<?php
declare(strict_types=1);

// ==============================================================================
// 1. ОПРЕДЕЛЕНИЕ БАЗОВОГО ПУТИ
// ==============================================================================
if (!defined('ENGINE_BASE_PATH')) {
    define('ENGINE_BASE_PATH', dirname(__DIR__));
}

// ==============================================================================
// 2. ПОДКЛЮЧЕНИЕ АВТОЗАГРУЗЧИКА COMPOSER
// ==============================================================================
$autoloadPath = ENGINE_BASE_PATH . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    echo "💥 ОШИБКА: vendor/autoload.php не найден.\n";
    echo "Запустите: composer install\n";
    exit(2);
}

require_once $autoloadPath;

// ==============================================================================
// 3. ЗАПУСК КОНСТИТУЦИОННОГО СУДА
// ==============================================================================
echo "⚖️  Конституционный Суд (Lex) запущен...\n";
echo "📂 Сканирование: " . ENGINE_BASE_PATH . "\n\n";

try {
    $engine = new \Law\RuleEngine(ENGINE_BASE_PATH . '/law/manifest.php');
    $violations = $engine->run(ENGINE_BASE_PATH);

    if (empty($violations)) {
        echo "✅ ЗАКОН СОБЛЮДЕН. Нарушений не обнаружено.\n";
        exit(0);
    }

    echo "❌ ОБНАРУЖЕНЫ НАРУШЕНИЯ КОНСТИТУЦИИ:\n";
    echo str_repeat('-', 80) . "\n";

    $failCount = 0;
    $warnCount = 0;

    foreach ($violations as $v) {
        $color = $v->severity === 'FAIL' ? '31' : '33';
        if ($v->severity === 'FAIL') $failCount++;
        else $warnCount++;

        $relPath = str_replace(ENGINE_BASE_PATH . DIRECTORY_SEPARATOR, '', $v->filePath);
        // Нормализация разделителей для Windows (H:\... и H:/...) — консистентный вывод CLI
        $engineBaseNorm = rtrim(str_replace('\\', '/', ENGINE_BASE_PATH), '/');
        $relPath = str_replace($engineBaseNorm . '/', '', rtrim(str_replace('\\', '/', $v->filePath), '/'));
        
        echo sprintf(
            "\033[%sm[%s] %s (Строка: %d)\033[0m\n",
            $color,
            $v->severity,
            $relPath,
            $v->line
        );
        echo sprintf("  Правило: %s\n", $v->ruleId);
        echo sprintf("  Сообщение: %s\n", $v->message);
        echo sprintf("  💡 Подсказка: %s\n\n", $v->hint);
    }

    echo str_repeat('-', 80) . "\n";
    echo sprintf("Итог: %d FAIL, %d WARN\n", $failCount, $warnCount);
    
    exit($failCount > 0 ? 1 : 0);

} catch (\Throwable $e) {
    echo "💥 КРИТИЧЕСКАЯ ОШИБКА СУДА: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(2);
}