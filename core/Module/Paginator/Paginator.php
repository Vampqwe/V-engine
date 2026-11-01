<?php
declare(strict_types=1);

namespace Core\Module\Paginator;

use Core\Contract\PaginationResult;
use Core\Contract\PaginatorInterface;

/**
 * Пагинатор (P2, §8.3.2). Работает с любым источником данных:
 *   - массив        => срезы + count();
 *   - callable      => fn(int $offset, int $limit, array $criteria): array{items: list, total: int}.
 *
 * Защита от DoS: perPage ограничен maxPerPage (по умолчанию 200).
 * Страница нормализуется в диапазон [1 .. pages].
 */
final class Paginator implements PaginatorInterface
{
    public const VERSION = '1.0.0';

    /** @var \Closure(array, int, int): array{items: list<mixed>, total: int} */
    private readonly \Closure $fetcher;

    /** @var list<mixed>|null источник для массивного режима */
    private ?array $boundArray;

    private readonly int $maxPerPage;

    /** @param array|callable $source */
    public function __construct(array|callable $source, int $maxPerPage = 200)
    {
        if ($maxPerPage < 1) {
            throw new \InvalidArgumentException('maxPerPage must be >= 1.');
        }
        $this->maxPerPage = $maxPerPage;

        if (is_array($source)) {
            $this->boundArray = array_values($source);
            $this->fetcher = static fn (array $arr, int $offset, int $limit): array
                => ['items' => array_slice($arr, $offset, $limit), 'total' => count($arr)];
        } else {
            $this->boundArray = null;
            $src = \Closure::fromCallable($source);
            $this->fetcher = static fn (array $criteria, int $offset, int $limit): array => $src($offset, $limit, $criteria);
        }
    }

    public function paginate(int $page, int $perPage, array $criteria = []): PaginationResult
    {
        $perPage = max(1, min($perPage, $this->maxPerPage));

        // Шаг 1: total (берём страницу 1 как дешёвый зонд).
        $probe = $this->slice(1, $perPage, $criteria);
        $total = $probe['total'];
        $pages = max(1, (int) ceil($total / $perPage));

        // Шаг 2: нормализация страницы и реальный срез.
        $current = max(1, min($page, $pages));
        $items = $current === 1 ? $probe['items'] : $this->slice($current, $perPage, $criteria)['items'];

        return new PaginationResult(
            items: $items,
            total: $total,
            pages: $pages,
            currentPage: $current,
            perPage: $perPage,
            links: [
                'first' => 1,
                'last'  => $pages,
                'prev'  => $current > 1 ? $current - 1 : null,
                'next'  => $current < $pages ? $current + 1 : null,
            ],
        );
    }

    /**
     * @return array{items: list<mixed>, total: int}
     */
    private function slice(int $page, int $perPage, array $criteria): array
    {
        $offset = ($page - 1) * $perPage;
        $fetcher = $this->fetcher;
        return $fetcher($this->boundArray ?? $criteria, $offset, $perPage);
    }
}
