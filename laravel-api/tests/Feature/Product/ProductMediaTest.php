<?php

namespace Tests\Feature\Product;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductMedia;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;
use Tests\TestCase;

class ProductMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
    }

    private function token(?string $role = 'employee'): string
    {
        $employee = Employee::factory()->create();
        if ($role !== null) {
            $employee->assignRole($role);
        }

        return $employee->createToken('crm')->plainTextToken;
    }

    public function test_can_upload_multiple_images(): void
    {
        $product = Product::factory()->create();

        $res = $this->withToken($this->token())
            ->postJson("/api/crm/products/{$product->id}/media", [
                'images' => [
                    UploadedFile::fake()->image('a.jpg', 800, 600),
                    UploadedFile::fake()->image('b.jpg', 800, 600),
                ],
            ])->assertCreated();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame(2, $product->media()->count());

        foreach ($product->media as $media) {
            Storage::disk('public')->assertExists($media->path);
        }
    }

    public function test_first_uploaded_image_is_primary(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token())
            ->postJson("/api/crm/products/{$product->id}/media", [
                'images' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ])->assertCreated();

        $this->assertSame(1, $product->media()->where('is_primary', true)->count());
    }

    public function test_images_are_resized_down(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token())
            ->postJson("/api/crm/products/{$product->id}/media", [
                'images' => [UploadedFile::fake()->image('big.jpg', 2400, 1200)],
            ])->assertCreated();

        $media = $product->media()->firstOrFail();
        $binary = Storage::disk('public')->get($media->path);
        $this->assertLessThanOrEqual(1200, Image::read($binary)->width());
    }

    public function test_validation_rejects_non_image(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token())
            ->postJson("/api/crm/products/{$product->id}/media", [
                'images' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
            ])->assertStatus(422)->assertJsonValidationErrors(['images.0']);
    }

    public function test_validation_rejects_too_large(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token())
            ->postJson("/api/crm/products/{$product->id}/media", [
                'images' => [UploadedFile::fake()->image('big.jpg')->size(6000)], // 6 MB > 5 MB
            ])->assertStatus(422)->assertJsonValidationErrors(['images.0']);
    }

    public function test_list_media(): void
    {
        $product = Product::factory()->create();
        ProductMedia::factory()->count(2)->create(['product_id' => $product->id]);

        $this->withToken($this->token())
            ->getJson("/api/crm/products/{$product->id}/media")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_delete_is_soft(): void
    {
        $media = ProductMedia::factory()->create();

        $this->withToken($this->token())
            ->deleteJson("/api/crm/media/{$media->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($media);
    }

    public function test_set_primary(): void
    {
        $product = Product::factory()->create();
        $a = ProductMedia::factory()->create(['product_id' => $product->id, 'is_primary' => true]);
        $b = ProductMedia::factory()->create(['product_id' => $product->id, 'is_primary' => false]);

        $this->withToken($this->token())
            ->putJson("/api/crm/media/{$b->id}/primary")
            ->assertOk();

        $this->assertFalse($a->fresh()->is_primary);
        $this->assertTrue($b->fresh()->is_primary);
    }

    public function test_customer_cannot_upload(): void
    {
        $product = Product::factory()->create();
        $token = Customer::factory()->create()->createToken('shop')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/crm/products/{$product->id}/media", [
                'images' => [UploadedFile::fake()->image('a.jpg')],
            ])->assertUnauthorized();
    }

    public function test_employee_without_permission_forbidden(): void
    {
        $product = Product::factory()->create();

        $this->withToken($this->token(null))
            ->getJson("/api/crm/products/{$product->id}/media")->assertForbidden();
    }
}
