<?php
declare(strict_types=1);

namespace Tests\Fixture\Container;

final class WireMid implements DepAInterface
{
    public function __construct(public readonly WireLeaf $leaf)
    {
    }
}
