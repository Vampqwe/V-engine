<?php
declare(strict_types=1);

namespace Tests\Fixture\Container;

use Core\Contract\LoggerInterface;

final class NeedsContract
{
    public static int $ctorCalls = 0;

    public function __construct(public readonly LoggerInterface $logger)
    {
        self::$ctorCalls++;
    }
}
