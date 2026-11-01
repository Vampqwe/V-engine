<?php
declare(strict_types=1);

/**
 * Секция 'session' — хранение сессий сайта (доступ: config->get('session.*')).
 *   driver : file       — файлы в storage/sessions (по умолчанию, без зависимостей);
 *            redis      — только Redis (быстро, но требует сервер Redis);
 *            migrating  — чтение из обоих хранилищ + запись в Redis с
 *                         graceful read-through: при падении Redis сессии
 *                         продолжают работать на файлах (без потери пользователей).
 *   name   : имя cookie сессии; path — каталог внутри storage; ttl — секунды.
 * Значения SESSION_DRIVER / SESSION_REDIS_HOST могут переопределяться из .env.
 */
return [
    'driver' => 'file',            // file | redis | migrating
    'name'   => 'LEXSID',
    'path'   => 'sessions',        // storage/sessions
    'ttl'    => 1800,
    'redis'  => [                  // используется при driver=redis|migrating
        'host' => null,            // из .env: SESSION_REDIS_HOST
        'port' => 6379,
    ],
];
