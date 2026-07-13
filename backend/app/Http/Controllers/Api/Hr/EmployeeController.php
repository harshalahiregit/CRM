<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreEmployeeRequest;
use App\Models\Hr\HrEmployee;
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
            $this->employeeService->list($request->user()->tenant_id, $request->only(['status', 'department', 'designation', 'joined_from', 'search']))
        );
    }

    public function store(StoreEmployeeRequest $request)
    {
        $employee = $this->employeeService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($employee, 201);
    }

    public function show(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);

        return response()->json($employee);
    }

    /** Full enterprise profile — recruitment + onboarding + offer + documents + timeline. */
    public function profile(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);

        return response()->json($this->employeeService->profile($employee));
    }

    public function update(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);
        $this->assertCanManage($request);

        $data = $request->validate([
            'name'                   => 'sometimes|required|string',
            'email'                  => 'nullable|email',
            'phone'                  => 'nullable|string',
            'dob'                    => 'nullable|date',
            'gender'                 => 'nullable|in:Male,Female,Other,Prefer not to say',
            'address'                => 'nullable|string',
            'department'             => 'sometimes|required|string',
            'designation'            => 'sometimes|required|string',
            'reporting_manager_name' => 'nullable|string',
            'joining_date'           => 'nullable|date',
            'probation_end_date'     => 'nullable|date',
            'confirmation_date'      => 'nullable|date',
            'status'                 => 'nullable|in:Active,On Leave,Inactive',
        ]);

        $updated = $this->employeeService->update($employee, $data, $request->user());

        return response()->json($updated);
    }

    public function destroy(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);
        $this->assertCanManage($request);

        $this->employeeService->destroy($employee);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->employeeService->stats($request->user()->tenant_id));
    }

    private function assertTenant(Request $request, HrEmployee $employee): void
    {
        abort_unless((int) $employee->tenant_id === (int) $request->user()->tenant_id, 404, 'Employee not found');
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage employees');
    }
}
