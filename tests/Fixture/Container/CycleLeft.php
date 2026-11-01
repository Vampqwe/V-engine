<?php
declare(strict_types=1);

namespace Tests\Fixture\Container;

final class CycleLeft
{
    public function __construct(public CycleRight $right)
    {
    }
}
