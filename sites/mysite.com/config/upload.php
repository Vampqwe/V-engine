<?php
declare(strict_types=1);

/**
 * Секция 'upload' сайта (модуль FileUpload, P2).
 *  max_size     — лимит в байтах (реальный размер файла проверяется сервером)
 *  allowed_ext  — белый список расширений (пустой массив = без фильтра по ext)
 *  allowed_mime — белый список РЕАЛЬНЫХ MIME (определяются по содержимому)
 */
return [
    'max_size'     => 5 * 1024 * 1024,
    'allowed_ext'  => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md', 'zip'],
    'allowed_mime' => [], // пустой: фильтр по расширениям + согласованию MIME/ext
];
