<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт отправки уведомлений (P2, §16.2).
 *
 * Транспорт выбирается конфигом секции 'notification':
 *  - 'log'   — запись в канал Logger (dev-режим, всегда доступен);
 *  - 'smtp'  — SMTP через stream_socket_client (БЕЗ cURL, §34.1-safe);
 *  - 'mail'  — PHP mail() как fallback.
 *
 * Идемпотность и retry — не здесь: для фоновой отправки задача ставится
 * в Queue, а NotificationInterface вызывается из обработчика.
 */
interface NotificationInterface
{
    /**
     * Отправить письмо.
     *
     * @param string[] $to      Адреса получателей.
     * @param string   $subject Тема.
     * @param string   $body    Текст (plain).
     * @param array    $options Дополнительные заголовки (cc, reply_to).
     *
     * @return bool Успех транспорта.
     */
    public function sendMail(array $to, string $subject, string $body, array $options = []): bool;
}
