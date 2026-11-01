<?php
declare(strict_types=1);

/**
 * Секция 'upload' — модуль FileUpload (P2). Безопасность defense-in-depth:
 * клиентские MIME/имя/размер НЕ доверяются — всё проверяется сервером по
 * реальному содержимому (finfo). Исполняемые типы (php/sh/exe) запрещены
 * ВСЕГДА, даже если вы ошибочно внесёте их в белый список ниже.
 *   max_size     : лимит в байтах (проверяется по диску, не по отчёту формы);
 *   allowed_ext  : белый список расширений ([] = без фильтра по ext);
 *   allowed_mime : белый список РЕАЛЬНЫХ MIME ([] = только согласование ext/mime);
 *   dir          : подкаталог storage для сохранённых файлов (jailed-root §20).
 */
return [
    'max_size'     => 5 * 1024 * 1024,
    'allowed_ext'  => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md', 'zip'],
    'allowed_mime' => [],
    'dir'          => 'uploads',        // storage/uploads
];
