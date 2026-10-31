<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт контекста сайта (Статья 16.10).
 * getEngineFolder() — служебное расширение для модулей ядра (Config);
 * пользовательскому коду недоступно осознанно: контракт виден, но папка движка
 * изнутри песочницы сайта недостижима (Статья 23).
 */
interface SiteContextInterface {
    public function getBaseFolder(): string;
    public function getBaseUrl(): string;
    public function getEnvPath(): string;
    public function getConfigPath(): string;

    /** Корень движка ENGINE_BASE_PATH (только для нужд ядра). */
    public function getEngineFolder(): string;
}
