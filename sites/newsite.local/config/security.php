<?php
declare(strict_types=1);

/**
 * Секция 'security' — модуль Security (CSRF, хеширование паролей).
 *   csrf_ttl      : время жизни CSRF-токена, сек (по умолчанию 2 часа);
 *   password_algo : 'default' (= PASSWORD_DEFAULT, сейчас bcrypt/argon по PHP)
 *                   или 'argon2id'. Секрет подписи берётся из APP_SECRET (.env).
 */
return [
    'csrf_ttl'      => 7200,
    'password_algo' => 'default',
];
