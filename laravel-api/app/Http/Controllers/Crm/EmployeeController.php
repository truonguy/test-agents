<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreEmployeeRequest;
use App\Models\Employee;
use App\Services\Crm\EmployeeManagementService;
use Illuminate\Http\JsonResponse;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeManagementService $employees,
    ) {}

    public function index(): JsonResponse
    {
        $data = $this->employees->list()->map(fn (Employee $e) => $this->present($e));

        return response()->json(['data' => $data]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employees->create($request->validated());

        return response()->json($this->present($employee), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'status' => $employee->status->value,
            'role' => $employee->getRoleNames()->first(),
        ];
    }
}
