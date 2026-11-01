<?php
declare(strict_types=1);

use Core\Contract\DatabaseInterface;

return static function (DatabaseInterface $db): void {
    $db->run('DROP TABLE IF EXISTS demo_users');
};
