<?php
declare(strict_types=1);

/**
 * Секция 'view' — шаблонизатор сайта (TemplateView, нативный PHP § "PHP-First").
 *   dir : каталог шаблонов внутри jailed-root storage.
 * Рендер: $kernel->get(ViewInterface::class)->render('home', ['title' => ...])
 * найдёт файл sites/newsite.local/storage/views/home.phtml.
 * В шаблоне доступна переменная $esc(string): string — экранирование вывода.
 */
return [
    'dir' => 'views',              // storage/views
];
