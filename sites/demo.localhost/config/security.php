<?php
declare(strict_types=1);

return [
    // APP_SECRET берётся из .env сайта (app.secret) — здесь только политика.
    'csrf_ttl'      => 7200,   // сек. жизнь CSRF-токена
    'password_algo' => 'default', // null => PHP default (bcrypt/argon по окружению)
];
