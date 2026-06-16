<?php

namespace App\Repositories\Eloquent;

use App\Models\Employee;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EmployeeRepository implements EmployeeRepositoryInterface
{
    public function findByEmail(string $email): ?Employee
    {
        return Employee::query()->where('email', $email)->first();
    }

    public function create(array $data): Employee
    {
        return Employee::create($data);
    }

    public function all(): Collection
    {
        return Employee::query()->latest('id')->get();
    }
}
