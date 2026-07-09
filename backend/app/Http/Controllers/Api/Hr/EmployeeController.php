<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreEmployeeRequest;
use App\Models\HrEmployee;
use App\Services\Hr\EmployeeService;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(private EmployeeService $employeeService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->employeeService->list($request->user()->tenant_id, $request->only(['status', 'department', 'search']))
        );
    }

    public function store(StoreEmployeeRequest $request)
    {
        $employee = $this->employeeService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($employee, 201);
    }

    public function show(HrEmployee $employee)
    {
        return response()->json($employee);
    }

    public function update(Request $request, HrEmployee $employee)
    {
        $updated = $this->employeeService->update($employee, $request->all());

        return response()->json($updated);
    }

    public function destroy(HrEmployee $employee)
    {
        $this->employeeService->destroy($employee);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->employeeService->stats($request->user()->tenant_id));
    }
}
