<?php

namespace App\Repositories\Contracts;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

interface EmployeeRepositoryInterface
{
    public function findByEmail(string $email): ?Employee;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Employee;

    /**
     * @return Collection<int, Employee>
     */
    public function all(): Collection;
}
