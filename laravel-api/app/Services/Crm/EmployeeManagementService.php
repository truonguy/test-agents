<?php

namespace App\Services\Crm;

use App\Enums\UserStatus;
use App\Models\Employee;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EmployeeManagementService
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $employees,
    ) {}

    /**
     * @return Collection<int, Employee>
     */
    public function list(): Collection
    {
        return $this->employees->all();
    }

    /**
     * Tạo employee mới và gán role (employee|admin).
     *
     * @param  array{name: string, email: string, password: string, role: string}  $data
     */
    public function create(array $data): Employee
    {
        $employee = $this->employees->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => UserStatus::ACTIVE,
        ]);

        $employee->assignRole($data['role']);

        return $employee;
    }
}
