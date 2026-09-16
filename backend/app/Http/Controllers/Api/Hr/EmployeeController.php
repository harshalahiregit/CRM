<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreEmployeeRequest;
use App\Http\Requests\Hr\UpdateEmployeeDetailRequest;
use App\Models\Hr\HrEmployee;
use App\Rules\Hr\ValidWorkState;
use App\Services\Hr\EmployeeDetailService;
use App\Services\Hr\EmployeeService;
use App\Support\Hr\WorkStates;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(
        private EmployeeService $employeeService,
        private EmployeeDetailService $details,
    )
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->employeeService->list($request->user()->tenant_id, $request->only(['status', 'department', 'designation', 'joined_from', 'search', 'per_page']))
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

    /**
     * The extended record: personal, address, education, emergency contact,
     * bank, identity and statutory.
     *
     * Always returns every field, null where unset, so the form renders without
     * having to special-case a person who has none of it filled in yet.
     */
    public function detail(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);

        return response()->json([
            'status' => 'success',
            'data'   => $this->details->get($employee),
        ]);
    }

    public function updateDetail(UpdateEmployeeDetailRequest $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);

        $this->details->save($employee, $request->validated(), $request->user());

        return response()->json([
            'status'  => 'success',
            'message' => 'Employee details saved',
            'data'    => $this->details->get($employee->fresh()),
        ]);
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
            'work_state'             => ['nullable', 'string', 'max:80', new ValidWorkState],
            'joining_date'           => 'nullable|date',
            'probation_end_date'     => 'nullable|date',
            'confirmation_date'      => 'nullable|date',
            'status'                 => 'nullable|in:Active,On Leave,Inactive',
            // #29 — without these in the whitelist, validate() drops them and the
            // org-chart settings silently never save.
            'worker_type'            => 'nullable|in:employee,consultant,freelancer',
            'include_in_org_chart'   => 'nullable|boolean',
            // HR grants app access on the employee record; Staff Management owns
            // what somebody can do inside the CRM. Two different questions.
            'app_login_enabled'      => 'nullable|boolean',
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

    /**
     * The work-state vocabulary for the employee form's dropdown.
     *
     * Served from the backend so the list the UI offers and the list PT rules are
     * matched against can never drift apart.
     */
    public function workStates()
    {
        return response()->json(['data' => WorkStates::options()]);
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
