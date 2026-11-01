<?php
declare(strict_types=1);

/**
 * Секция 'database' — подключение к БД этого сайта (доступ: config->get('database.*')).
 * ПРИНЦИП: сайт живёт БЕЗ базы данных. enabled=false => ядро стартует нормально,
 * а любое обращение к БД даёт DatabaseNotConfiguredException с подсказкой (§8).
 * Драйверы PDO: mysql / pgsql / sqlite. Секреты (user/pass) — только в .env (DB_*).
 */
return [
    'enabled' => false,
    'dsn'     => null,   // напр. mysql:host=127.0.0.1;dbname=newsite;charset=utf8mb4
    'user'    => null,   // из .env: DB_USER
    'pass'    => null,   // из .env: DB_PASS
];
