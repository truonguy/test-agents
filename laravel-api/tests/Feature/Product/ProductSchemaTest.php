<?php

namespace Tests\Feature\Product;

use App\Enums\PublishStatus;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_and_key_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('categories', ['id', 'parent_id', 'name', 'slug', 'is_active', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('products', ['id', 'category_id', 'name', 'slug', 'description', 'publish_status', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('product_variants', ['id', 'product_id', 'sku', 'size', 'color', 'price', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('inventories', ['id', 'product_variant_id', 'reserved_stock', 'available_stock']));
        $this->assertTrue(Schema::hasColumns('product_media', ['id', 'product_id', 'path', 'disk', 'is_primary', 'sort_order', 'deleted_at']));
    }

    public function test_soft_delete_works(): void
    {
        $category = Category::factory()->create();
        $category->delete();

        $this->assertSoftDeleted($category);
        $this->assertSame(0, Category::count());
        $this->assertSame(1, Category::withTrashed()->count());
    }

    public function test_slug_is_unique_for_products(): void
    {
        Product::factory()->create(['slug' => 'dup-slug']);

        $this->expectException(QueryException::class);
        Product::factory()->create(['slug' => 'dup-slug']);
    }

    public function test_sku_is_unique_for_variants(): void
    {
        ProductVariant::factory()->create(['sku' => 'SKU-1']);

        $this->expectException(QueryException::class);
        ProductVariant::factory()->create(['sku' => 'SKU-1']);
    }

    public function test_inventory_is_one_to_one_with_variant(): void
    {
        $variant = ProductVariant::factory()->create();
        Inventory::factory()->create(['product_variant_id' => $variant->id]);

        $this->expectException(QueryException::class);
        Inventory::factory()->create(['product_variant_id' => $variant->id]);
    }

    public function test_relationships(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $product = Product::factory()->create(['category_id' => $child->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $inventory = Inventory::factory()->create(['product_variant_id' => $variant->id]);
        $media = ProductMedia::factory()->create(['product_id' => $product->id]);

        $this->assertTrue($product->category->is($child));
        $this->assertTrue($child->parent->is($parent));
        $this->assertTrue($parent->children->first()->is($child));
        $this->assertTrue($variant->product->is($product));
        $this->assertTrue($variant->inventory->is($inventory));
        $this->assertTrue($product->variants->first()->is($variant));
        $this->assertTrue($product->media->first()->is($media));
        $this->assertInstanceOf(PublishStatus::class, $product->publish_status);
    }
}
