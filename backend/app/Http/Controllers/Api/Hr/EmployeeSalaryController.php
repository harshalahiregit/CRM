<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\EmployeeSalaryService;
use Illuminate\Http\Request;

/**
 * Payroll → Employee Salary Assignment (Phase 3). Thin: validate, delegate,
 * return JSON. Reads open to HR users; writes require HR-queue management.
 * Tenant-scoped and audited via the service. No hard delete — status toggle only.
 */
class EmployeeSalaryController extends Controller
{
    public function __construct(private EmployeeSalaryService $service)
    {
    }

    public function show(Request $request, int $employeeId)
    {
        return response()->json($this->service->forEmployee($employeeId, $this->tenant($request)));
    }

    /** Read-only salary revision ledger for one employee. */
    public function revisions(Request $request, int $employeeId)
    {
        return response()->json(['data' => $this->service->revisions($employeeId, $this->tenant($request))]);
    }

    public function store(Request $request, int $employeeId)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'salary_structure_id' => 'required|integer',
            'effective_from'      => 'required|date',
            'effective_to'        => 'nullable|date|after_or_equal:effective_from',
            'reason'              => 'nullable|string|max:255',
        ]);

        return response()->json($this->service->assign($employeeId, $data, $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $employeeId, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'salary_structure_id' => 'nullable|integer',
            'effective_from'      => 'sometimes|required|date',
            'effective_to'        => 'nullable|date|after_or_equal:effective_from',
        ]);

        return response()->json($this->service->update($employeeId, $id, $data, $this->tenant($request), $request->user()));
    }

    public function updateStatus(Request $request, int $employeeId, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json($this->service->setStatus($employeeId, $id, (bool) $data['is_active'], $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage payroll settings');
    }
}
