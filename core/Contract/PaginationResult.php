<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Результат пагинации (§16.2 Конституции). Иммутабельный value object.
 */
final class PaginationResult
{
    /**
     * @param list<mixed>            $items
     * @param array<string, ?int>    $links  first/last/prev/next — страницы (null = недоступна)
     */
    public function __construct(
        private readonly array $items,
        private readonly int $total,
        private readonly int $pages,
        private readonly int $currentPage,
        private readonly int $perPage,
        private readonly array $links,
    ) {
    }

    /** @return list<mixed> */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getPages(): int
    {
        return $this->pages;
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function hasMore(): bool
    {
        return $this->currentPage < $this->pages;
    }

    /** @return array<string, ?int> */
    public function getLinks(): array
    {
        return $this->links;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
