<?php
declare(strict_types=1);

// Сайт живёт без БД (§): enabled=false — ядро стартует, обращения к БД
// дают DatabaseNotConfiguredException с подсказкой.
return [
    'enabled' => false,
    'dsn'     => null,
    'user'    => null,
    'pass'    => null,
];
