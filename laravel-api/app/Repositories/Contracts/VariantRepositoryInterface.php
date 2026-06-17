<?php

namespace App\Repositories\Contracts;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;

interface VariantRepositoryInterface
{
    /**
     * @return Collection<int, ProductVariant>
     */
    public function forProduct(Product $product): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Product $product, array $data): ProductVariant;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductVariant $variant, array $data): ProductVariant;

    public function delete(ProductVariant $variant): void;
}
