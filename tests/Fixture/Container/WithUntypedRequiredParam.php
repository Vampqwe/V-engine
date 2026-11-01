<?php
declare(strict_types=1);

namespace Tests\Fixture\Container;

final class WithUntypedRequiredParam
{
    public function __construct(public $anything)
    {
    }
}
