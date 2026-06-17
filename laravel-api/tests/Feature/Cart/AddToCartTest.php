<?php

namespace Tests\Feature\Cart;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddToCartTest extends TestCase
{
    use RefreshDatabase;

    private function customerToken(?Customer $customer = null): string
    {
        $customer ??= Customer::factory()->create();

        return $customer->createToken('shop')->plainTextToken;
    }

    private function publishedVariant(): ProductVariant
    {
        $product = Product::factory()->published()->create();

        return ProductVariant::factory()->create(['product_id' => $product->id]);
    }

    public function test_add_item_creates_cart_and_item(): void
    {
        $customer = Customer::factory()->create();
        $variant = $this->publishedVariant();

        $this->withToken($this->customerToken($customer))
            ->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 2])
            ->assertCreated();

        $this->assertDatabaseHas('carts', ['customer_id' => $customer->id]);
        $this->assertDatabaseHas('cart_items', ['product_variant_id' => $variant->id, 'quantity' => 2]);
    }

    public function test_duplicate_variant_merges_quantity(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->customerToken($customer);
        $variant = $this->publishedVariant();

        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 2])->assertSuccessful();
        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 3])->assertSuccessful();

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', ['product_variant_id' => $variant->id, 'quantity' => 5]);
    }

    public function test_unpublished_product_is_rejected(): void
    {
        $draft = Product::factory()->create(); // DRAFT
        $variant = ProductVariant::factory()->create(['product_id' => $draft->id]);

        $this->withToken($this->customerToken())
            ->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['product_variant_id']);
    }

    public function test_nonexistent_variant_is_rejected(): void
    {
        $this->withToken($this->customerToken())
            ->postJson('/api/cart/items', ['product_variant_id' => 9999, 'quantity' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['product_variant_id']);
    }

    public function test_quantity_must_be_positive(): void
    {
        $variant = $this->publishedVariant();

        $this->withToken($this->customerToken())
            ->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['quantity']);
    }

    public function test_employee_token_cannot_add(): void
    {
        $variant = $this->publishedVariant();
        $token = Employee::factory()->create()->createToken('crm')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertUnauthorized();
    }

    public function test_requires_authentication(): void
    {
        $variant = $this->publishedVariant();

        $this->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertUnauthorized();
    }
}
