<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт рендеринга (P1, §16.1).
 */
interface ViewInterface
{
    public function render(string $template, array $data = []): string;

    public function exists(string $template): bool;
}

