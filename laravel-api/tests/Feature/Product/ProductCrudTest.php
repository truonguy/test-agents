<?php

namespace Tests\Feature\Product;

use App\Enums\PublishStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCrudTest extends TestCase
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

    public function test_can_create_product_defaults_to_draft(): void
    {
        $category = Category::factory()->create();

        $this->withToken($this->token())
            ->postJson('/api/crm/products', [
                'name' => 'Cool Hoodie',
                'category_id' => $category->id,
                'description' => 'Warm',
            ])
            ->assertCreated()
            ->assertJson([
                'name' => 'Cool Hoodie',
                'slug' => 'cool-hoodie',
                'publish_status' => PublishStatus::DRAFT->value,
            ]);

        $this->assertDatabaseHas('products', ['slug' => 'cool-hoodie', 'category_id' => $category->id]);
    }

    public function test_category_must_exist(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/crm/products', ['name' => 'X', 'category_id' => 9999])
            ->assertStatus(422)->assertJsonValidationErrors(['category_id']);

        $this->withToken($this->token())
            ->postJson('/api/crm/products', ['name' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors(['category_id']);
    }

    public function test_slug_is_unique(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['slug' => 'dupe', 'name' => 'Dupe']);

        $this->withToken($this->token())
            ->postJson('/api/crm/products', ['name' => 'Dupe', 'category_id' => $category->id])
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }

    public function test_cannot_set_published_via_crud(): void
    {
        $category = Category::factory()->create();

        $this->withToken($this->token())
            ->postJson('/api/crm/products', [
                'name' => 'Y',
                'category_id' => $category->id,
                'publish_status' => PublishStatus::PUBLISHED->value,
            ])
            ->assertStatus(422)->assertJsonValidationErrors(['publish_status']);
    }

    public function test_can_create_archived(): void
    {
        $category = Category::factory()->create();

        $this->withToken($this->token())
            ->postJson('/api/crm/products', [
                'name' => 'Z',
                'category_id' => $category->id,
                'publish_status' => PublishStatus::ARCHIVED->value,
            ])
            ->assertCreated()->assertJson(['publish_status' => PublishStatus::ARCHIVED->value]);
    }

    public function test_crm_index_sees_all_statuses(): void
    {
        Product::factory()->create();                 // DRAFT
        Product::factory()->published()->create();     // PUBLISHED
        Product::factory()->archived()->create();      // ARCHIVED

        $res = $this->withToken($this->token())->getJson('/api/crm/products')->assertOk();
        $this->assertCount(3, $res->json('data'));
    }

    public function test_show_product(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token())
            ->getJson("/api/crm/products/{$product->id}")
            ->assertOk()->assertJson(['id' => $product->id, 'slug' => $product->slug]);
    }

    public function test_update_product(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token())
            ->putJson("/api/crm/products/{$product->id}", [
                'name' => 'Renamed Prod',
                'category_id' => $product->category_id,
            ])
            ->assertOk()->assertJson(['name' => 'Renamed Prod', 'slug' => 'renamed-prod']);
    }

    public function test_delete_is_soft(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token())
            ->deleteJson("/api/crm/products/{$product->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($product);
    }

    public function test_customer_cannot_access(): void
    {
        $token = Customer::factory()->create()->createToken('shop')->plainTextToken;
        $this->withToken($token)->getJson('/api/crm/products')->assertUnauthorized();
    }

    public function test_employee_without_permission_forbidden(): void
    {
        $this->withToken($this->token(null))->getJson('/api/crm/products')->assertForbidden();
    }
}
