<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShopLoginTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $payload)
    {
        return $this->postJson('/api/shop/auth/login', $payload);
    }

    /** AC-01.1 */
    public function test_active_customer_can_login(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'cust@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $res = $this->login(['email' => 'cust@example.com', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'type']);

        $this->assertSame('customer', $res->json('type'));
        $this->assertNotEmpty($res->json('access_token'));

        // token works on shop, AC-01.6: not on crm
        $token = $res->json('access_token');
        $this->withToken($token)->getJson('/api/shop/ping')->assertOk();
        $this->withToken($token)->getJson('/api/crm/ping')->assertUnauthorized();
    }

    /** AC-01.3 */
    public function test_wrong_password_returns_401(): void
    {
        Customer::factory()->create([
            'email' => 'cust@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->login(['email' => 'cust@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
    }

    /** AC-01.3 (unknown email — same generic response) */
    public function test_unknown_email_returns_401(): void
    {
        $this->login(['email' => 'nobody@example.com', 'password' => 'whatever'])
            ->assertUnauthorized();
    }

    /** AC-01.2 — employee email must not reveal existence; generic 401 */
    public function test_employee_email_cannot_login_via_shop(): void
    {
        Employee::factory()->create([
            'email' => 'emp@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->login(['email' => 'emp@example.com', 'password' => 'secret123'])
            ->assertUnauthorized();
    }

    /** AC-01.4 — inactive/locked → 403 */
    public function test_inactive_customer_is_forbidden(): void
    {
        Customer::factory()->inactive()->create([
            'email' => 'inact@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->login(['email' => 'inact@example.com', 'password' => 'secret123'])
            ->assertForbidden();
    }

    public function test_locked_customer_is_forbidden(): void
    {
        Customer::factory()->locked()->create([
            'email' => 'locked@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->login(['email' => 'locked@example.com', 'password' => 'secret123'])
            ->assertForbidden();
    }

    /** AC-01.5 — validation */
    public function test_validation_requires_email_and_password(): void
    {
        $this->login([])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_validation_rejects_malformed_email(): void
    {
        $this->login(['email' => 'not-an-email', 'password' => 'secret123'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }
}
