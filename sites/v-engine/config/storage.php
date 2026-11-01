<?php
declare(strict_types=1);

// Jailed-root FileStorage: все пути в контрактах относительны этого каталога.
// Абсолютный путь = папка данных САЙТА (многосайтовость: домен -> sites/<домен>).
return [
    'path' => __DIR__ . '/../storage',
];
