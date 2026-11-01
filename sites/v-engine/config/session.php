<?php
declare(strict_types=1);

return [
    'driver' => 'file',        // file | redis | migrating (read-through миграция)
    'name'   => 'LEXSID',
    'path'   => 'sessions',    // storage/sessions (jailed-root relative)
    'ttl'    => 1800,
];
