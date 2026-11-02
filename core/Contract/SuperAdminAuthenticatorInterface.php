<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт аутентификации СУПЕР-АДМИНИСТРАТОРА ядра (§8.4, вне БД).
 *
 * Панель ядра обязана работать ДО подключения сайта к базе данных:
 * учётная запись живит в файле system/admin.key (Argon2id-хеш), сессия —
 * в подписанной HMAC-cookie, защита от перебора — файловый lockout.
 *
 * Реализация по умолчанию: Core\Module\Admin\SuperAdminAuth.
 * Расширение (например, штатный DatabaseAuthenticator) подменяет биндинг
 * через контейнер — код панели менять не требуется (§16 «две дороги»).
 */
interface SuperAdminAuthenticatorInterface
{
    /** Есть ли вообще настроенный ключ супер-админа (файл admin.key). */
    public function isConfigured(): bool;

    /**
     * Проверить связку логин+пароль. Не бросает исключений: false = отказ.
     */
    public function attempt(string $login, string $password, string $ip = ''): bool;

    /** Валиден ли токен сессии из cookie (подпись HMAC + срок жизни). */
    public function validateToken(?string $rawCookie): bool;

    /** Подписать и вернуть значение cookie для установки в ответ. */
    public function issueToken(): string;

    /** Секрет смены пароля: true, если хеш требует обновления (needsRehash). */
    public function passwordNeedsRehash(): bool;

    /** Заблокирован ли вход по IP после серии неудач (file-lockout)? */
    public function isLockedOut(string $ip = ''): bool;

    /** Сколько секунд осталось до разблокировки (0 — не заблокирован). */
    public function lockoutRemaining(string $ip = ''): int;
}
