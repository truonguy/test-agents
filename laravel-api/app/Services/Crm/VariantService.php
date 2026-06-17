<?php

namespace App\Services\Crm;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Repositories\Contracts\VariantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class VariantService
{
    public function __construct(
        private readonly VariantRepositoryInterface $variants,
    ) {}

    /**
     * @return Collection<int, ProductVariant>
     */
    public function listFor(Product $product): Collection
    {
        return $this->variants->forProduct($product);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Product $product, array $data): ProductVariant
    {
        return $this->variants->create($product, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        return $this->variants->update($variant, $data);
    }

    public function delete(ProductVariant $variant): void
    {
        $this->variants->delete($variant);
    }
}
