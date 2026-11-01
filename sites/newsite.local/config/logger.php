<?php
declare(strict_types=1);

/**
 * Секция 'logger' — каналы записей этого сайта (доступ: config->get('logger.*')).
 * Все пути относительны jailed-root хранилища = sites/newsite.local/storage (§20).
 *   channels : список разрешённых каналов (app/error/security/database + свои);
 *   dir      : каталог логов внутри storage; файл ротируется по дням;
 *   channel  : канал по умолчанию для вызовов без явного channel().
 */
return [
    'channels' => ['app', 'error', 'security', 'database'],
    'dir'      => 'logs',
    'channel'  => 'app',
];
