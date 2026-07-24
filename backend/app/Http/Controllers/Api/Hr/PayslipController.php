<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\PayslipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Payroll → Payslips (Phase 5). Thin: validate, delegate, return JSON (or stream
 * the PDF). Reads open to HR users; generate requires HR-queue management.
 * Tenant-scoped and audited via the service.
 */
class PayslipController extends Controller
{
    public function __construct(private PayslipService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['year', 'month', 'status', 'search'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    /** POST /hr/payroll/runs/{id}/generate-payslips */
    public function generate(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json($this->service->generateForRun($id, $this->tenant($request), $request->user()), 201);
    }

    public function download(Request $request, int $id)
    {
        $file = $this->service->download($id, $this->tenant($request), $request->user());

        return Storage::disk($file['disk'])->download($file['path'], $file['filename']);
    }

    public function employeePayslips(Request $request, int $employeeId)
    {
        return response()->json($this->service->forEmployee($employeeId, $this->tenant($request)));
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
