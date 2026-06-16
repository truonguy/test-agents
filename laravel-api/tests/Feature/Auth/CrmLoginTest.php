<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Customer;
use App\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CrmLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function login(array $payload)
    {
        return $this->postJson('/api/crm/auth/login', $payload);
    }

    private function makeEmployee(string $role, array $attrs = []): Employee
    {
        $employee = Employee::factory()->create(array_merge([
            'password' => Hash::make('secret123'),
        ], $attrs));
        $employee->assignRole($role);

        return $employee;
    }

    /** AC-02.1 */
    public function test_active_employee_can_login_with_role(): void
    {
        $employee = $this->makeEmployee('employee', ['email' => 'emp@example.com']);

        $res = $this->login(['email' => 'emp@example.com', 'password' => 'secret123'])
            ->assertOk()
            ->assertJson(['type' => 'employee', 'role' => 'employee']);

        $token = $res->json('access_token');
        $this->assertNotEmpty($token);

        // AC-02.8: token CRM không dùng được cho Shop
        $this->withToken($token)->getJson('/api/crm/ping')->assertOk();
        $this->withToken($token)->getJson('/api/shop/ping')->assertUnauthorized();
    }

    /** AC-02.2 */
    public function test_admin_logs_in_with_admin_role(): void
    {
        $this->makeEmployee('admin', ['email' => 'admin@example.com']);

        $this->login(['email' => 'admin@example.com', 'password' => 'secret123'])
            ->assertOk()
            ->assertJson(['type' => 'employee', 'role' => 'admin']);
    }

    /** AC-02.3 — customer không login được CRM (generic 401) */
    public function test_customer_email_cannot_login_crm(): void
    {
        Customer::factory()->create([
            'email' => 'cust@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->login(['email' => 'cust@example.com', 'password' => 'secret123'])
            ->assertUnauthorized();
    }

    /** AC-02.4 */
    public function test_wrong_password_returns_401(): void
    {
        $this->makeEmployee('employee', ['email' => 'emp@example.com']);

        $this->login(['email' => 'emp@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
    }

    /** AC-02.5 */
    public function test_inactive_employee_is_forbidden(): void
    {
        $this->makeEmployee('employee', ['email' => 'emp@example.com'])
            ->update(['status' => UserStatus::INACTIVE]);

        $this->login(['email' => 'emp@example.com', 'password' => 'secret123'])
            ->assertForbidden();
    }

    public function test_validation_requires_email_and_password(): void
    {
        $this->login([])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    /** AC-02.7 — audit log ghi cả success & fail */
    public function test_audit_log_records_failure_and_success(): void
    {
        $this->makeEmployee('employee', ['email' => 'emp@example.com']);

        // FAIL
        $this->login(['email' => 'emp@example.com', 'password' => 'wrong'])->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', [
            'guard' => 'employee',
            'email' => 'emp@example.com',
            'action' => 'login',
            'result' => 'FAIL',
        ]);

        // SUCCESS
        $this->login(['email' => 'emp@example.com', 'password' => 'secret123'])->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'guard' => 'employee',
            'email' => 'emp@example.com',
            'action' => 'login',
            'result' => 'SUCCESS',
        ]);
    }
}
