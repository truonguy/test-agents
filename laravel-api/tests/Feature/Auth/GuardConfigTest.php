<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class GuardConfigTest extends TestCase
{
    public function test_customer_guard_uses_sanctum_with_customers_provider(): void
    {
        $this->assertSame('sanctum', config('auth.guards.customer.driver'));
        $this->assertSame('customers', config('auth.guards.customer.provider'));
        $this->assertSame('App\Models\Customer', config('auth.providers.customers.model'));
    }

    public function test_employee_guard_uses_sanctum_with_employees_provider(): void
    {
        $this->assertSame('sanctum', config('auth.guards.employee.driver'));
        $this->assertSame('employees', config('auth.guards.employee.provider'));
        $this->assertSame('App\Models\Employee', config('auth.providers.employees.model'));
    }

    public function test_sanctum_and_permission_configs_are_published(): void
    {
        $this->assertIsArray(config('sanctum.stateful'), 'sanctum config not published');
        $this->assertIsArray(config('permission.models'), 'permission config not published');
    }
}
