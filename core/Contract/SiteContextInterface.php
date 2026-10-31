<?php
declare(strict_types=1);

namespace Core\Contract;

interface SiteContextInterface {
    public function getBaseFolder(): string;
    public function getBaseUrl(): string;
    public function getEnvPath(): string;
    public function getConfigPath(): string;
}