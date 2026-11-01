<?php
declare(strict_types=1);

/**
 * Секция 'cache' — кэш сайта (доступ: config->get('cache.*')).
 *   driver : file — атомарные файлы в storage/cache (fallback всегда работает);
 *   ttl    : время жизни значения по умолчанию, сек.
 */
return [
    'driver' => 'file',
    'dir'    => 'cache',           // storage/cache
    'ttl'    => 3600,
];
