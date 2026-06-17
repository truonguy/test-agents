<?php

namespace App\Services\Order;

use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\ProductVariant;

/**
 * Thao tác tồn kho an toàn race (spec §0):
 * - reserve : available -= qty, reserved += qty (checkout). Thiếu tồn → InsufficientStockException.
 * - release : available += qty, reserved -= qty (cancel order).
 * - consume : reserved -= qty (order DELIVERED — xuất kho thật).
 *
 * Dùng `lockForUpdate` để chống oversell khi checkout đồng thời. NÊN gọi trong DB transaction
 * (CheckoutService bọc transaction) để lock có hiệu lực tới cuối giao dịch.
 */
class InventoryReservationService
{
    public function reserve(ProductVariant $variant, int $quantity): void
    {
        $inventory = $this->lock($variant);

        if ($inventory === null || $inventory->available_stock < $quantity) {
            throw new InsufficientStockException;
        }

        $inventory->update([
            'available_stock' => $inventory->available_stock - $quantity,
            'reserved_stock' => $inventory->reserved_stock + $quantity,
        ]);
    }

    public function release(ProductVariant $variant, int $quantity): void
    {
        $inventory = $this->lock($variant);

        if ($inventory === null) {
            return;
        }

        $inventory->update([
            'available_stock' => $inventory->available_stock + $quantity,
            'reserved_stock' => max(0, $inventory->reserved_stock - $quantity),
        ]);
    }

    public function consume(ProductVariant $variant, int $quantity): void
    {
        $inventory = $this->lock($variant);

        if ($inventory === null) {
            return;
        }

        $inventory->update([
            'reserved_stock' => max(0, $inventory->reserved_stock - $quantity),
        ]);
    }

    private function lock(ProductVariant $variant): ?Inventory
    {
        return Inventory::query()
            ->where('product_variant_id', $variant->id)
            ->lockForUpdate()
            ->first();
    }
}
