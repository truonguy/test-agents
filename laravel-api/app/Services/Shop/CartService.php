<?php

namespace App\Services\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Repositories\Contracts\CartRepositoryInterface;

class CartService
{
    public function __construct(
        private readonly CartRepositoryInterface $carts,
    ) {}

    /**
     * Thêm variant vào cart của customer; trùng variant → cộng dồn quantity (merge).
     */
    public function addItem(Customer $customer, int $variantId, int $quantity): CartItem
    {
        $cart = $this->carts->activeCartFor($customer);

        $item = $cart->items()->firstOrNew(['product_variant_id' => $variantId]);
        $item->quantity = ($item->quantity ?? 0) + $quantity;
        $item->save();

        return $item;
    }

    public function summary(Cart $cart): array
    {
        $cart->loadMissing('items.variant');

        $subtotal = $cart->items->sum(fn (CartItem $item) => (float) $item->variant->price * $item->quantity);

        return [
            'items' => $cart->items,
            'count' => $cart->items->sum('quantity'),
            'subtotal' => round($subtotal, 2),
        ];
    }
}
