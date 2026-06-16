<?php

namespace App\Repositories\Contracts;

use App\Models\Employee;

interface EmployeeRepositoryInterface
{
    public function findByEmail(string $email): ?Employee;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Employee;

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Employee>
     */
    public function all(): \Illuminate\Database\Eloquent\Collection;
}
