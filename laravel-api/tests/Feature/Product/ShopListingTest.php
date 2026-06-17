<?php

namespace Tests\Feature\Product;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopListingTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProduct(array $attrs = [], float $price = 50): Product
    {
        $product = Product::factory()->published()->create($attrs);
        ProductVariant::factory()->create(['product_id' => $product->id, 'price' => $price]);

        return $product;
    }

    public function test_only_published_and_not_deleted_are_listed(): void
    {
        $this->publishedProduct();
        $this->publishedProduct();
        Product::factory()->create();             // DRAFT
        Product::factory()->archived()->create(); // ARCHIVED
        $deleted = $this->publishedProduct();
        $deleted->delete();                        // soft-deleted

        $res = $this->getJson('/api/products')->assertOk();
        $this->assertCount(2, $res->json('data'));
    }

    public function test_listing_is_public_no_token_required(): void
    {
        $this->publishedProduct();

        $this->getJson('/api/products')->assertOk();
    }

    public function test_filter_by_category(): void
    {
        $catA = Category::factory()->create();
        $catB = Category::factory()->create();
        $this->publishedProduct(['category_id' => $catA->id]);
        $this->publishedProduct(['category_id' => $catA->id]);
        $this->publishedProduct(['category_id' => $catB->id]);

        $res = $this->getJson("/api/products?category_id={$catA->id}")->assertOk();
        $this->assertCount(2, $res->json('data'));
    }

    public function test_filter_by_price_range(): void
    {
        $this->publishedProduct(price: 10);
        $this->publishedProduct(price: 50);
        $this->publishedProduct(price: 200);

        $res = $this->getJson('/api/products?price_min=20&price_max=100')->assertOk();
        $this->assertCount(1, $res->json('data'));
    }

    public function test_sort_by_price_ascending(): void
    {
        $cheap = $this->publishedProduct(price: 5);
        $mid = $this->publishedProduct(price: 50);
        $expensive = $this->publishedProduct(price: 500);

        $res = $this->getJson('/api/products?sort=price_asc')->assertOk();
        $ids = array_column($res->json('data'), 'id');

        $this->assertSame([$cheap->id, $mid->id, $expensive->id], $ids);
    }

    public function test_pagination(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->publishedProduct();
        }

        $res = $this->getJson('/api/products?per_page=5')->assertOk();
        $this->assertCount(5, $res->json('data'));
        $this->assertSame(8, $res->json('meta.total'));
    }
}
