<?php

namespace App\Services\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Phân trang tái sử dụng: chuẩn hoá per_page (có chặn trần) + envelope phản hồi thống nhất.
 * Dùng cho mọi endpoint danh sách có phân trang (Shop listing, Search, CRM lists...).
 */
class PaginationService
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * Chuẩn hoá per_page: ép kiểu, chặn dưới (>=1) và chặn trên (<=MAX).
     */
    public function resolvePerPage(mixed $requested, int $default = self::DEFAULT_PER_PAGE): int
    {
        $perPage = (int) ($requested ?? $default);

        if ($perPage < 1) {
            $perPage = $default;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    /**
     * Phân trang query với per_page đã chuẩn hoá.
     *
     * @param  Builder<Model>|Relation<Model>  $query
     */
    public function paginate(Builder|Relation $query, mixed $perPage = null): LengthAwarePaginator
    {
        return $query->paginate($this->resolvePerPage($perPage));
    }

    /**
     * Envelope phản hồi thống nhất: { data: [...], meta: {...} }.
     *
     * @return array<string, mixed>
     */
    public function format(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }
}
