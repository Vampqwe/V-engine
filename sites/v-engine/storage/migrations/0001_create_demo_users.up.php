<?php
declare(strict_types=1);

use Core\Contract\DatabaseInterface;

return static function (DatabaseInterface $db): void {
    $db->run('CREATE TABLE IF NOT EXISTS demo_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        created_at TEXT NOT NULL
    )');
};
