<?php
declare(strict_types=1);

/**
 * МАНИФЕСТ КОНСТИТУЦИОННОГО СУДА (LEX)
 * Единый источник правды для статических проверок.
 */
return [
    'rules' => [
        'C-001' => [
            'article' => '§31. Аварийный люк',
            'scope' => 'public/index.php',
            'severity' => 'FAIL',
            'detector' => 'EmergencyBunkerDetector',
            'message' => 'Отсутствует register_shutdown_function или ENGINE_BASE_PATH',
            'hint' => 'Добавьте аварийный обработчик в начало public/index.php'
        ],
        'C-009' => [
            'article' => '§9. Строгий типизированный код',
            'scope' => 'ALL',
            'severity' => 'FAIL',
            'detector' => 'StrictTypesDetector',
            'message' => 'Отсутствует declare(strict_types=1);',
            'hint' => 'Добавьте declare(strict_types=1); сразу после <?php'
        ],
        'C-011' => [
            'article' => '§11. Запрет прямого создания объектов ядра',
            'scope' => 'USER',
            'severity' => 'FAIL',
            'detector' => 'NewCoreInstantiationDetector',
            'message' => 'Обнаружено прямое создание объекта ядра (new \\Core\\...)',
            'hint' => 'Используйте $container->get(Interface::class) вместо прямого создания'
        ],
        'C-031' => [
            'article' => '§33. Изолированные каналы логирования',
            'scope' => 'USER',
            'severity' => 'FAIL',
            'detector' => 'DirectFileLogDetector',
            'message' => 'Обнаружен прямой вызов file_put_contents или fopen для логов',
            'hint' => 'Используйте LoggerInterface::channel("name")'
        ],
        'C-040' => [
            'article' => '§40. Безопасный вывод в шаблонах',
            'scope' => 'USER',
            'severity' => 'FAIL',
            'detector' => 'UnsafeOutputDetector',
            'message' => 'Небезопасный вывод <?= без экранирования в .phtml-шаблоне',
            'hint' => 'Используйте <?= $esc($var) ?> (шорткат Security::escapeHtml)'
        ]
    ]
];