<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Customer;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

class MigrationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('customers'));
        $this->assertTrue(Schema::hasColumns('customers', [
            'id', 'name', 'email', 'password', 'status', 'created_at', 'updated_at',
        ]));
    }

    public function test_employees_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('employees'));
        $this->assertTrue(Schema::hasColumns('employees', [
            'id', 'name', 'email', 'password', 'status', 'created_at', 'updated_at',
        ]));
    }

    public function test_audit_logs_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertTrue(Schema::hasColumns('audit_logs', [
            'id', 'guard', 'email', 'ip', 'user_agent', 'action', 'result', 'created_at',
        ]));
    }

    public function test_customer_uses_api_tokens_and_status_cast(): void
    {
        $customer = Customer::factory()->create();

        $this->assertContains(HasApiTokens::class, class_uses_recursive($customer));
        $this->assertInstanceOf(UserStatus::class, $customer->status);
        $this->assertSame(UserStatus::ACTIVE, $customer->status);

        $token = $customer->createToken('test');
        $this->assertNotEmpty($token->plainTextToken);
    }

    public function test_employee_uses_api_tokens_and_roles(): void
    {
        $employee = Employee::factory()->create();
        $uses = class_uses_recursive($employee);

        $this->assertContains(HasApiTokens::class, $uses);
        $this->assertContains(HasRoles::class, $uses);

        Role::create(['name' => 'employee', 'guard_name' => 'employee']);
        $employee->assignRole('employee');

        $this->assertTrue($employee->hasRole('employee'));
    }

    public function test_email_is_unique_per_table(): void
    {
        Customer::factory()->create(['email' => 'dup@example.com']);

        $this->expectException(QueryException::class);
        Customer::factory()->create(['email' => 'dup@example.com']);
    }
}
