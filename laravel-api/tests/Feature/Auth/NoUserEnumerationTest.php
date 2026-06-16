<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Đảm bảo không lộ sự tồn tại của tài khoản (user enumeration) ở login & forgot-password.
 */
class NoUserEnumerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_shop_login_unknown_vs_wrong_password_are_identical(): void
    {
        Customer::factory()->create(['email' => 'real@example.com', 'password' => Hash::make('secret123')]);

        $unknown = $this->postJson('/api/shop/auth/login', ['email' => 'ghost@example.com', 'password' => 'secret123']);
        $wrong = $this->postJson('/api/shop/auth/login', ['email' => 'real@example.com', 'password' => 'nope']);

        $this->assertSame(401, $unknown->status());
        $this->assertSame(401, $wrong->status());
        $this->assertSame($unknown->json(), $wrong->json());
    }

    public function test_crm_login_unknown_vs_wrong_password_are_identical(): void
    {
        $emp = Employee::factory()->create(['email' => 'real@example.com', 'password' => Hash::make('secret123')]);
        $emp->assignRole('employee');

        $unknown = $this->postJson('/api/crm/auth/login', ['email' => 'ghost@example.com', 'password' => 'secret123']);
        $wrong = $this->postJson('/api/crm/auth/login', ['email' => 'real@example.com', 'password' => 'nope']);

        $this->assertSame(401, $unknown->status());
        $this->assertSame(401, $wrong->status());
        $this->assertSame($unknown->json(), $wrong->json());
    }

    public function test_forgot_password_known_vs_unknown_are_identical(): void
    {
        Customer::factory()->create(['email' => 'real@example.com']);

        $known = $this->postJson('/api/shop/auth/forgot-password', ['email' => 'real@example.com']);
        $unknown = $this->postJson('/api/shop/auth/forgot-password', ['email' => 'ghost@example.com']);

        $this->assertSame(200, $known->status());
        $this->assertSame(200, $unknown->status());
        $this->assertSame($known->json(), $unknown->json());
    }
}
