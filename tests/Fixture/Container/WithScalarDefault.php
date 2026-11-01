<?php
declare(strict_types=1);

namespace Tests\Fixture\Container;

final class WithScalarDefault
{
    public function __construct(public readonly string $mode = 'default-mode')
    {
    }
}
