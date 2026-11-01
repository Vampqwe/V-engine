<?php
declare(strict_types=1);

namespace Core\Contract;

interface PaginatorInterface
{
    /**
     * @param int                    $page     1-based; значения <=0 и >максимума нормализуются
     * @param int                    $perPage  ограничивается maxPerPage модуля
     * @param array<string, mixed>   $criteria фильтры (интерпретация — за источником данных)
     */
    public function paginate(int $page, int $perPage, array $criteria = []): PaginationResult;
}
