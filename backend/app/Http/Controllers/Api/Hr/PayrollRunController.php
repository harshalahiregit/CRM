<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrPayrollRun;
use App\Services\Hr\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payroll → Payroll Processing (Phase 4). Thin: validate, delegate, return JSON.
 * Reads open to HR users; writes (create / process / status) require HR-queue
 * management. Tenant-scoped and audited via the service.
 */
class PayrollRunController extends Controller
{
    public function __construct(private PayrollService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->listRuns($this->tenant($request), $request->only(['year', 'status'])));
    }

    public function store(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer|min:2000|max:2100',
        ]);

        return response()->json($this->service->createRun($data, $this->tenant($request), $request->user()), 201);
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->showRun($id, $this->tenant($request)));
    }

    public function process(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json($this->service->process($id, $this->tenant($request), $request->user()));
    }

    public function records(Request $request, int $id)
    {
        return response()->json($this->service->records($id, $this->tenant($request)));
    }

    /** The frozen component + statutory breakdown behind one record. */
    public function recordLines(Request $request, int $recordId)
    {
        return response()->json(['data' => $this->service->recordLines($recordId, $this->tenant($request))]);
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['status' => ['required', Rule::in(HrPayrollRun::STATUSES)]]);

        return response()->json($this->service->setStatus($id, $data['status'], $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage payroll');
    }
}
