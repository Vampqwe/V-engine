<?php
declare(strict_types=1);

/**
 * Секция 'core' — базовые настройки сайта (доступ: config->get('core.*')).
 *   debug    : true показывает текст ошибок в HTTP-ответах 500.
 *              В ПРОДАКШЕНЕ ТОЛЬКО false (§31 — никаких утечек стека наружу).
 *   timezone : часовой пояс PHP для этого сайта (метки логов, дедлайны задач).
 */
return [
    'debug'    => false,
    'timezone' => 'Europe/Minsk',
];
