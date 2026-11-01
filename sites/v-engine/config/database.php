<?php
declare(strict_types=1);

// § "сайт живёт без БД": enabled=false => DatabaseInterface деградирует,
// ядро поднимается, запросы к БД дают понятную DatabaseNotConfiguredException.
return [
    'enabled' => false,
    'dsn'     => null,         // напр. mysql:host=127.0.0.1;dbname=demo;charset=utf8mb4
    'user'    => null,
    'pass'    => null,
];
