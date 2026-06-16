<?php

namespace Tests\Feature\Auth;

use App\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function tokenFor(string $role): string
    {
        $employee = Employee::factory()->create();
        $employee->assignRole($role);

        return $employee->createToken('crm')->plainTextToken;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'New Emp',
            'email' => 'new.emp@example.com',
            'password' => 'secret123',
            'role' => 'employee',
        ], $override);
    }

    /** AC-06.5 — admin tạo employee → 201, employee mới login được CRM */
    public function test_admin_creates_employee_who_can_login(): void
    {
        $this->withToken($this->tokenFor('admin'))
            ->postJson('/api/crm/employees', $this->payload())
            ->assertCreated()
            ->assertJson(['email' => 'new.emp@example.com', 'role' => 'employee']);

        $this->assertDatabaseHas('employees', ['email' => 'new.emp@example.com']);

        // employee mới login được CRM
        $this->postJson('/api/crm/auth/login', [
            'email' => 'new.emp@example.com',
            'password' => 'secret123',
        ])->assertOk()->assertJson(['type' => 'employee', 'role' => 'employee']);
    }

    public function test_admin_can_create_admin_role(): void
    {
        $this->withToken($this->tokenFor('admin'))
            ->postJson('/api/crm/employees', $this->payload(['email' => 'boss@example.com', 'role' => 'admin']))
            ->assertCreated()->assertJson(['role' => 'admin']);
    }

    /** employee thường không được tạo employee → 403 */
    public function test_regular_employee_cannot_create_employee(): void
    {
        $this->withToken($this->tokenFor('employee'))
            ->postJson('/api/crm/employees', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseMissing('employees', ['email' => 'new.emp@example.com']);
    }

    public function test_admin_can_list_employees(): void
    {
        $this->withToken($this->tokenFor('admin'))
            ->getJson('/api/crm/employees')
            ->assertOk()->assertJsonStructure(['data']);
    }

    public function test_validation_rejects_bad_input(): void
    {
        $token = $this->tokenFor('admin');

        // thiếu field
        $this->withToken($token)->postJson('/api/crm/employees', [])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'password', 'role']);

        // role không hợp lệ
        $this->withToken($token)->postJson('/api/crm/employees', $this->payload(['role' => 'superuser']))
            ->assertStatus(422)->assertJsonValidationErrors(['role']);
    }

    public function test_duplicate_employee_email_rejected(): void
    {
        Employee::factory()->create(['email' => 'dup@example.com']);

        $this->withToken($this->tokenFor('admin'))
            ->postJson('/api/crm/employees', $this->payload(['email' => 'dup@example.com']))
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }
}
