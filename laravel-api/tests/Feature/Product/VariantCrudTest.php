<?php

namespace Tests\Feature\Product;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariantCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function token(?string $role = 'employee'): string
    {
        $employee = Employee::factory()->create();
        if ($role !== null) {
            $employee->assignRole($role);
        }

        return $employee->createToken('crm')->plainTextToken;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'sku' => 'SKU-ALPHA',
            'size' => 'M',
            'color' => 'Red',
            'price' => 19.99,
        ], $override);
    }

    public function test_can_add_variant_to_product(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token())
            ->postJson("/api/crm/products/{$product->id}/variants", $this->payload())
            ->assertCreated()
            ->assertJson(['sku' => 'SKU-ALPHA', 'product_id' => $product->id]);

        $this->assertDatabaseHas('product_variants', ['sku' => 'SKU-ALPHA', 'product_id' => $product->id]);
    }

    public function test_product_can_have_multiple_variants(): void
    {
        $product = Product::factory()->create();
        $token = $this->token();

        $this->withToken($token)->postJson("/api/crm/products/{$product->id}/variants", $this->payload(['sku' => 'A']))->assertCreated();
        $this->withToken($token)->postJson("/api/crm/products/{$product->id}/variants", $this->payload(['sku' => 'B']))->assertCreated();

        $this->assertSame(2, $product->variants()->count());
    }

    public function test_sku_is_unique(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['sku' => 'DUP']);

        $this->withToken($this->token())
            ->postJson("/api/crm/products/{$product->id}/variants", $this->payload(['sku' => 'DUP']))
            ->assertStatus(422)->assertJsonValidationErrors(['sku']);
    }

    public function test_price_cannot_be_negative(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token())
            ->postJson("/api/crm/products/{$product->id}/variants", $this->payload(['price' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['price']);
    }

    public function test_validation_requires_sku_and_price(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token())
            ->postJson("/api/crm/products/{$product->id}/variants", ['size' => 'M'])
            ->assertStatus(422)->assertJsonValidationErrors(['sku', 'price']);
    }

    public function test_list_variants_of_product(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->count(2)->create(['product_id' => $product->id]);
        ProductVariant::factory()->create(); // khác product

        $res = $this->withToken($this->token())
            ->getJson("/api/crm/products/{$product->id}/variants")->assertOk();
        $this->assertCount(2, $res->json('data'));
    }

    public function test_update_variant(): void
    {
        $variant = ProductVariant::factory()->create(['price' => 10]);

        $this->withToken($this->token())
            ->putJson("/api/crm/variants/{$variant->id}", ['sku' => $variant->sku, 'price' => 25.50])
            ->assertOk()->assertJson(['price' => '25.50']);
    }

    public function test_delete_is_soft(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->withToken($this->token())
            ->deleteJson("/api/crm/variants/{$variant->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($variant);
    }

    public function test_customer_cannot_access(): void
    {
        $product = Product::factory()->create();
        $token = Customer::factory()->create()->createToken('shop')->plainTextToken;

        $this->withToken($token)->getJson("/api/crm/products/{$product->id}/variants")->assertUnauthorized();
    }

    public function test_employee_without_permission_forbidden(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token(null))
            ->getJson("/api/crm/products/{$product->id}/variants")->assertForbidden();
    }
}
