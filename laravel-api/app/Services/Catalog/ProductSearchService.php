<?php

namespace App\Services\Catalog;

use App\Enums\PublishStatus;
use App\Models\Product;
use App\Services\Support\PaginationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Truy vấn catalog phía Shop (public). Chỉ trả sản phẩm PUBLISHED (SoftDeletes tự ẩn bản đã xoá).
 * Giá hiển thị/sort = min(variant.price) qua `withMin`.
 */
class ProductSearchService
{
    public function __construct(
        private readonly PaginationService $pagination,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $query = Product::query()
            ->where('publish_status', PublishStatus::PUBLISHED->value)
            ->withMin('variants', 'price');

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['price_min'])) {
            $query->whereHas('variants', fn ($q) => $q->where('price', '>=', $filters['price_min']));
        }

        if (isset($filters['price_max'])) {
            $query->whereHas('variants', fn ($q) => $q->where('price', '<=', $filters['price_max']));
        }

        match ($filters['sort'] ?? null) {
            'price_asc' => $query->orderBy('variants_min_price'),
            'price_desc' => $query->orderByDesc('variants_min_price'),
            'newest' => $query->latest('id'),
            default => $query->latest('id'),
        };

        return $this->pagination->paginate($query, $filters['per_page'] ?? null);
    }
}
