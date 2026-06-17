<?php

namespace Tests\Feature\Order;

use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Services\Order\InventoryReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReservationTest extends TestCase
{
    use RefreshDatabase;

    private InventoryReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InventoryReservationService;
    }

    private function variantWithStock(int $available, int $reserved = 0): ProductVariant
    {
        $variant = ProductVariant::factory()->create();
        Inventory::factory()->create([
            'product_variant_id' => $variant->id,
            'available_stock' => $available,
            'reserved_stock' => $reserved,
        ]);

        return $variant;
    }

    public function test_reserve_moves_available_to_reserved(): void
    {
        $variant = $this->variantWithStock(10);

        $this->service->reserve($variant, 3);

        $this->assertDatabaseHas('inventories', [
            'product_variant_id' => $variant->id,
            'available_stock' => 7,
            'reserved_stock' => 3,
        ]);
    }

    public function test_reserve_insufficient_throws_and_keeps_inventory(): void
    {
        $variant = $this->variantWithStock(2);

        try {
            $this->service->reserve($variant, 5);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException) {
            // ok
        }

        $this->assertDatabaseHas('inventories', [
            'product_variant_id' => $variant->id,
            'available_stock' => 2,
            'reserved_stock' => 0,
        ]);
    }

    public function test_reserve_without_inventory_throws(): void
    {
        $variant = ProductVariant::factory()->create(); // không có inventory

        $this->expectException(InsufficientStockException::class);
        $this->service->reserve($variant, 1);
    }

    public function test_reserve_exact_available_reaches_zero(): void
    {
        $variant = $this->variantWithStock(4);

        $this->service->reserve($variant, 4);

        $this->assertDatabaseHas('inventories', [
            'product_variant_id' => $variant->id,
            'available_stock' => 0,
            'reserved_stock' => 4,
        ]);
    }

    public function test_release_returns_reserved_to_available(): void
    {
        $variant = $this->variantWithStock(7, 3);

        $this->service->release($variant, 3);

        $this->assertDatabaseHas('inventories', [
            'product_variant_id' => $variant->id,
            'available_stock' => 10,
            'reserved_stock' => 0,
        ]);
    }

    public function test_consume_reduces_reserved_only(): void
    {
        $variant = $this->variantWithStock(7, 3);

        $this->service->consume($variant, 3);

        $this->assertDatabaseHas('inventories', [
            'product_variant_id' => $variant->id,
            'available_stock' => 7,
            'reserved_stock' => 0,
        ]);
    }
}
