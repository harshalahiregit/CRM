<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\ExitSettlementService;
use Illuminate\Http\Request;

/**
 * Exit Management → Full & Final Settlement (Phase 5). Thin: validate, delegate,
 * return JSON. Reads open to HR users; workflow actions require HR-queue
 * management. Payroll is read-only throughout. Tenant-scoped, audited.
 */
class ExitSettlementController extends Controller
{
    public function __construct(private ExitSettlementService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->queue($this->tenant($request), $request->only(['employee_id', 'department', 'status', 'exit_type_id', 'settlement_month', 'search']), $request->user()));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    public function history(Request $request)
    {
        return response()->json($this->service->history($this->tenant($request), $request->only(['employee_id'])));
    }

    /** Employee Profile → Exit tab: read-only settlement summary. */
    public function forEmployee(Request $request, int $employee)
    {
        return response()->json($this->service->forEmployee($employee, $this->tenant($request)));
    }

    public function generate(Request $request, int $id)
    {
        $this->can($request);
        $inputs = $request->validate([
            'bonus'            => 'nullable|numeric|min:0',
            'incentives'       => 'nullable|numeric|min:0',
            'other_earnings'   => 'nullable|numeric|min:0',
            'notice_recovery'  => 'nullable|numeric|min:0',
            'buyout_recovery'  => 'nullable|numeric|min:0',
            'asset_recovery'   => 'nullable|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
        ]);

        return response()->json($this->service->generate($id, $inputs, $this->tenant($request), $request->user()));
    }

    public function review(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->review($id, $this->tenant($request), $request->user()));
    }

    public function approve(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->approve($id, $this->tenant($request), $request->user()));
    }

    public function settle(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->settle($id, $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to action full & final settlements');
    }
}
