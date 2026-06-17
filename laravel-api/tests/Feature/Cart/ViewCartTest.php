<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewCartTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_cart_returns_items_subtotal_and_count(): void
    {
        $customer = Customer::factory()->create();
        $cart = Cart::factory()->create(['customer_id' => $customer->id]);
        $a = ProductVariant::factory()->create(['price' => 10]);
        $b = ProductVariant::factory()->create(['price' => 5]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $a->id, 'quantity' => 2]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $b->id, 'quantity' => 1]);

        $res = $this->withToken($customer->createToken('shop')->plainTextToken)
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonStructure(['items', 'subtotal', 'count']);

        $this->assertSame(3, $res->json('count'));
        $this->assertEquals(25, $res->json('subtotal')); // 2*10 + 1*5
        $this->assertCount(2, $res->json('items'));
    }

    public function test_empty_cart_returns_zeroes(): void
    {
        $customer = Customer::factory()->create();

        $res = $this->withToken($customer->createToken('shop')->plainTextToken)
            ->getJson('/api/cart')->assertOk();

        $this->assertSame(0, $res->json('count'));
        $this->assertEquals(0, $res->json('subtotal'));
        $this->assertCount(0, $res->json('items'));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/cart')->assertUnauthorized();
    }
}
