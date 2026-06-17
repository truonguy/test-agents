<?php

namespace App\Services\Crm;

use App\Enums\PublishStatus;
use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
    ) {}

    /**
     * @return Collection<int, Product>
     */
    public function list(): Collection
    {
        return $this->products->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        // Mặc định DRAFT khi không truyền (DB default chưa nạp vào model in-memory).
        $data['publish_status'] ??= PublishStatus::DRAFT->value;

        return $this->products->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        return $this->products->update($product, $data);
    }

    public function delete(Product $product): void
    {
        $this->products->delete($product);
    }
}
