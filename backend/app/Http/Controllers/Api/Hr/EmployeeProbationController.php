<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\EmployeeProbationService;
use Illuminate\Http\Request;

/**
 * Probation Management → Employee Probation (Phase 2). Thin: validate, delegate,
 * return JSON. Reads open to HR users; writes require HR-queue management.
 * Tenant-scoped, audited.
 */
class EmployeeProbationController extends Controller
{
    public function __construct(private EmployeeProbationService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['employee_id', 'department', 'status', 'probation_policy_id', 'search'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    public function forEmployee(Request $request, int $employee)
    {
        return response()->json($this->service->forEmployee($employee, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'employee_id'          => 'required|integer',
            'probation_policy_id'  => 'nullable|integer',
            'probation_start_date' => 'nullable|date',
            'probation_end_date'   => 'nullable|date',
            'remarks'              => 'nullable|string',
        ]);

        return response()->json($this->service->assign($data, $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate([
            'probation_start_date' => 'nullable|date',
            'probation_end_date'   => 'nullable|date',
            'review_cycle'         => 'nullable|in:Weekly,Monthly,Quarterly',
            'remarks'              => 'nullable|string',
        ]);

        return response()->json($this->service->update($id, $data, $this->tenant($request), $request->user()));
    }

    public function activate(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->activate($id, $this->tenant($request), $request->user()));
    }

    public function cancel(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->cancel($id, $data, $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage employee probations');
    }
}
