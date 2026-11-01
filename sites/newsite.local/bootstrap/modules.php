<?php
declare(strict_types=1);

// ==============================================================================
// modules.php — ПОДКЛЮЧЕНИЕ ВАШИХ МОДУЛЕЙ (пользовательские модули сайта).
// Вызывается ТОЛЬКО bin/console.php (CLI: queue/scheduler/migrate) ДО boot():
// веб-точка входа подключает модули напрямую из bootstrap/routes.php при нужде.
// Формат: возвращает callable(KernelInterface $kernel) — до $kernel->boot().
// Каждый модуль реализует ModuleInterface (getName/getVersion/boot/getDependencies).
// ==============================================================================

use Core\Contract\KernelInterface;

return static function (KernelInterface $kernel): void {
    // Пример (раскомментируйте, когда создадите класс модуля):
    // $kernel->addUserModule(new \Site\Modules\BlogModule());
};
