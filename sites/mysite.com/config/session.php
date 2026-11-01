<?php
declare(strict_types=1);

return [
    'driver' => 'file',            // file | redis | migrating
    'name'   => 'LEXSID',
    'path'   => 'sessions',        // storage/sessions
    'ttl'    => 1800,
    'redis'  => [                   // driver=redis|migrating
        'host' => null,             // из .env: SESSION_REDIS_HOST
        'port' => 6379,
    ],
];
