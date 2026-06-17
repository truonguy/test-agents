<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateCartItemTest extends TestCase
{
    use RefreshDatabase;

    private function itemForToken(): array
    {
        $customer = Customer::factory()->create();
        $cart = Cart::factory()->create(['customer_id' => $customer->id]);
        $item = CartItem::factory()->create(['cart_id' => $cart->id, 'quantity' => 2]);

        return [$item, $customer->createToken('shop')->plainTextToken];
    }

    public function test_update_quantity(): void
    {
        [$item, $token] = $this->itemForToken();

        $this->withToken($token)
            ->putJson("/api/cart/items/{$item->id}", ['quantity' => 5])
            ->assertOk();

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 5]);
    }

    public function test_update_quantity_must_be_positive(): void
    {
        [$item, $token] = $this->itemForToken();

        $this->withToken($token)
            ->putJson("/api/cart/items/{$item->id}", ['quantity' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['quantity']);
    }

    public function test_cannot_update_another_customers_item(): void
    {
        [$item] = $this->itemForToken();
        $otherToken = Customer::factory()->create()->createToken('shop')->plainTextToken;

        $this->withToken($otherToken)
            ->putJson("/api/cart/items/{$item->id}", ['quantity' => 5])
            ->assertNotFound();

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 2]);
    }

    public function test_remove_item(): void
    {
        [$item, $token] = $this->itemForToken();

        $this->withToken($token)
            ->deleteJson("/api/cart/items/{$item->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_cannot_remove_another_customers_item(): void
    {
        [$item] = $this->itemForToken();
        $otherToken = Customer::factory()->create()->createToken('shop')->plainTextToken;

        $this->withToken($otherToken)
            ->deleteJson("/api/cart/items/{$item->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('cart_items', ['id' => $item->id]);
    }

    public function test_requires_authentication(): void
    {
        [$item] = $this->itemForToken();

        $this->putJson("/api/cart/items/{$item->id}", ['quantity' => 3])->assertUnauthorized();
        $this->deleteJson("/api/cart/items/{$item->id}")->assertUnauthorized();
    }
}
