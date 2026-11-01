<?php
declare(strict_types=1);

namespace Tests\Fixture\Container;

final class CycleRight
{
    public function __construct(public CycleLeft $left)
    {
    }
}
