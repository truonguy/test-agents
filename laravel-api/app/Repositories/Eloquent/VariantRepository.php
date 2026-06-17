<?php

namespace App\Repositories\Eloquent;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Repositories\Contracts\VariantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class VariantRepository implements VariantRepositoryInterface
{
    public function forProduct(Product $product): Collection
    {
        return $product->variants()->latest('id')->get();
    }

    public function create(Product $product, array $data): ProductVariant
    {
        return $product->variants()->create($data);
    }

    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        $variant->update($data);

        return $variant->refresh();
    }

    public function delete(ProductVariant $variant): void
    {
        $variant->delete();
    }
}
