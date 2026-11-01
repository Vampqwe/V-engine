<?php
declare(strict_types=1);

return [
    // Каналы изолированы (§33): app — общий поток, error — только ошибки.
    'channels' => ['app', 'error', 'security', 'database'],
    // Относительно jailed-root FileStorage (= sites/<домен>/storage).
    // Каталог логов сайта: storage/logs -> 'logs'.
    'dir' => 'logs',
    'channel' => 'app',
];