<?php
declare(strict_types=1);

namespace Tests\Unit\Paginator;

use Core\Module\Paginator\Paginator;
use PHPUnit\Framework\TestCase;

final class PaginatorTest extends TestCase
{
    public function testArraySourceSlicesCorrectly(): void
    {
        $items = range(1, 25);
        $p = new Paginator($items);

        $first = $p->paginate(1, 10);
        self::assertSame(range(1, 10), $first->getItems());
        self::assertSame(25, $first->getTotal());
        self::assertSame(3, $first->getPages());
        self::assertSame(1, $first->getCurrentPage());
        self::assertTrue($first->hasMore());
        self::assertNull($first->getLinks()['prev']);
        self::assertSame(2, $first->getLinks()['next']);

        $mid = $p->paginate(2, 10);
        self::assertSame(range(11, 20), $mid->getItems());
        self::assertSame(1, $mid->getLinks()['prev']);

        $last = $p->paginate(3, 10);
        self::assertSame(range(21, 25), $last->getItems());
        self::assertFalse($last->hasMore());
        self::assertNull($last->getLinks()['next']);
    }

    public function testOutOfRangePagesAreNormalized(): void
    {
        $p = new Paginator(range(1, 5));

        $low = $p->paginate(-7, 2);
        self::assertSame(1, $low->getCurrentPage());

        $high = $p->paginate(999, 2);
        self::assertSame(3, $high->getCurrentPage(), 'Страница за максимумом -> последняя.');
        self::assertSame([5], $high->getItems());
    }

    public function testEmptySourceYieldsSingleEmptyPage(): void
    {
        $p = new Paginator([]);
        $r = $p->paginate(1, 10);
        self::assertSame(0, $r->getTotal());
        self::assertSame(1, $r->getPages());
        self::assertTrue($r->isEmpty());
        self::assertFalse($r->hasMore());
    }

    public function testPerPageIsCappedToMaxPerPage(): void
    {
        $p = new Paginator(range(1, 1000), maxPerPage: 50);
        $r = $p->paginate(1, 10_000);
        self::assertSame(50, $r->getPerPage(), 'DoS-защита: perPage не больше лимита.');
        self::assertCount(50, $r->getItems());
        self::assertSame(20, $r->getPages());
    }

    public function testCallableSourceReceivesOffsetLimitCriteria(): void
    {
        $seen = [];
        $source = static function (int $offset, int $limit, array $criteria) use (&$seen): array {
            $seen[] = [$offset, $limit, $criteria];
            return ['items' => ["r{$offset}"], 'total' => 7];
        };
        $p = new Paginator($source);

        $r = $p->paginate(2, 3, ['status' => 'live']);
        self::assertSame(3, $r->getPages());
        self::assertSame(['r3'], $r->getItems());
        // Вызовы: зонд стр.1 (offset 0) + реальный срез стр.2 (offset 3).
        self::assertSame([[0, 3, ['status' => 'live']], [3, 3, ['status' => 'live']]], $seen);
    }

    public function testInvalidMaxPerPageRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Paginator([1, 2, 3], maxPerPage: 0);
    }

    public function testProbeOptimizationSkipsSecondFetchOnFirstPage(): void
    {
        $calls = 0;
        $source = static function () use (&$calls): array {
            $calls++;
            return ['items' => ['x'], 'total' => 2];
        };
        $p = new Paginator($source);
        $p->paginate(1, 1);
        self::assertSame(1, $calls, 'Первая страница использует зонд без второго запроса.');
    }
}
