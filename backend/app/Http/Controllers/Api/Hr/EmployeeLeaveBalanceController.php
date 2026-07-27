<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\EmployeeLeaveBalanceService;
use Illuminate\Http\Request;

/**
 * Leave → Employee Leave Balance & Allocation (Phase 2). Thin: validate, delegate,
 * return JSON. Reads open to HR users; writes require HR-queue management.
 * Tenant-scoped and audited via the service.
 */
class EmployeeLeaveBalanceController extends Controller
{
    public function __construct(private EmployeeLeaveBalanceService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['employee_id', 'leave_type_id', 'status'])));
    }

    public function forEmployee(Request $request, int $employee)
    {
        return response()->json($this->service->forEmployee($employee, $this->tenant($request)));
    }

    public function assign(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'employee_id'     => 'required|integer',
            'leave_policy_id' => 'required|integer',
            'effective_from'  => 'nullable|date',
        ]);

        return response()->json($this->service->assignPolicy($data, $this->tenant($request), $request->user()), 201);
    }

    public function allocate(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'employee_id'   => 'required|integer',
            'leave_type_id' => 'required|integer',
            'quantity'      => 'required|numeric|gt:0',
            'remarks'       => 'nullable|string',
        ]);

        return response()->json($this->service->allocate($data, $this->tenant($request), $request->user()), 201);
    }

    public function adjust(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'balance_id' => 'required|integer',
            'quantity'   => 'required|numeric',
            'remarks'    => 'nullable|string',
        ]);

        return response()->json($this->service->adjust($data, $this->tenant($request), $request->user()));
    }

    public function history(Request $request, int $balance)
    {
        return response()->json($this->service->history($balance, $this->tenant($request)));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage leave balances');
    }
}
