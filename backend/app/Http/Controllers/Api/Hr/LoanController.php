<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployeeLoan;
use App\Services\Hr\LoanEligibilityService;
use App\Services\Hr\LoanService;
use Illuminate\Http\Request;

/**
 * Employee Loan & Salary Advance. Thin: validate, delegate, return JSON.
 *
 * Approve / reject / disburse / close all require HR-queue management — each moves
 * money or commits the company to a deduction from someone's pay.
 */
class LoanController extends Controller
{
    public function __construct(
        private LoanService $service,
        private LoanEligibilityService $eligibility,
    ) {
    }

    public function meta(Request $request)
    {
        return response()->json([
            'statuses' => HrEmployeeLoan::STATUSES,
            'eligibility_limits' => $this->eligibility->limits($this->tenant($request)),
        ]);
    }

    /**
     * Affordability for a proposed EMI, before anything is saved.
     *
     * Read-only, so no permission gate — the UI calls it as figures are typed to
     * show the warning before a submit is rejected.
     */
    public function checkEligibility(Request $request)
    {
        $data = $request->validate([
            'employee_id'     => 'required|integer',
            'emi'             => 'required|numeric|min:0',
            'exclude_loan_id' => 'nullable|integer',
        ]);

        return response()->json($this->eligibility->evaluate(
            (int) $data['employee_id'], $this->tenant($request),
            (float) $data['emi'], (int) ($data['exclude_loan_id'] ?? 0),
        ));
    }

    /* ── Loan types ───────────────────────────────────────────────────── */

    public function types(Request $request)
    {
        return response()->json([
            'data' => $this->service->types($this->tenant($request), $request->only(['is_advance', 'is_active'])),
        ]);
    }

    public function saveType(Request $request, ?int $id = null)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'              => ($id ? 'sometimes|' : '').'required|string|max:150',
            'code'              => 'nullable|string|max:40',
            'is_advance'        => 'nullable|boolean',
            'max_amount'        => 'nullable|numeric|min:0',
            'max_tenure_months' => 'nullable|integer|min:1|max:600',
            'interest_rate'     => 'nullable|numeric|min:0|max:100',
            'requires_approval' => 'nullable|boolean',
            'description'       => 'nullable|string|max:1000',
            'is_active'         => 'nullable|boolean',
        ]);

        return response()->json(
            $this->service->saveType($id, $data, $this->tenant($request), $request->user()),
            $id ? 200 : 201
        );
    }

    public function destroyType(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->service->deleteType($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Loans ────────────────────────────────────────────────────────── */

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($this->tenant($request),
                $request->only(['status', 'employee_id', 'loan_type_id', 'is_advance'])),
        ]);
    }

    public function stats(Request $request)
    {
        return response()->json($this->service->stats($this->tenant($request)));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    public function save(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'employee_id'   => 'required|integer',
            'loan_type_id'  => 'required|integer',
            'principal'     => 'required|numeric|min:1',
            'tenure_months' => 'nullable|integer|min:1|max:600',
            'interest_rate' => 'nullable|numeric|min:0|max:100',
            'start_period'  => 'nullable|string|regex:/^\d{4}-\d{2}$/',
            'purpose'       => 'nullable|string|max:500',
            'remarks'       => 'nullable|string|max:1000',
        ]);

        return response()->json(
            $this->service->save($id, $data, $this->tenant($request), $request->user()),
            $id ? 200 : 201
        );
    }

    /** Amortisation preview — no side effects, so no permission gate. */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'principal'     => 'required|numeric|min:1',
            'tenure_months' => 'required|integer|min:1|max:600',
            'interest_rate' => 'nullable|numeric|min:0|max:100',
            'start_period'  => 'nullable|string|regex:/^\d{4}-\d{2}$/',
        ]);

        return response()->json($this->service->previewSchedule(
            (float) $data['principal'],
            (float) ($data['interest_rate'] ?? 0),
            (int) $data['tenure_months'],
            $data['start_period'] ?? null,
        ));
    }

    public function submit(Request $request, int $id)
    {
        return response()->json($this->service->submit($id, $this->tenant($request), $request->user()));
    }

    public function approve(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json($this->service->approve($id, $this->tenant($request), $request->user()));
    }

    public function reject(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['remarks' => 'required|string|max:1000']);

        return response()->json($this->service->reject($id, $data['remarks'], $this->tenant($request), $request->user()));
    }

    public function disburse(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'disbursed_on' => 'nullable|date',
            'start_period' => 'nullable|string|regex:/^\d{4}-\d{2}$/',
        ]);

        return response()->json($this->service->disburse($id, $data, $this->tenant($request), $request->user()));
    }

    public function close(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['remarks' => 'required|string|max:1000']);

        return response()->json($this->service->close($id, $data['remarks'], $this->tenant($request), $request->user()));
    }

    public function cancel(Request $request, int $id)
    {
        return response()->json($this->service->cancel($id, $this->tenant($request), $request->user()));
    }

    public function waiveInstallment(Request $request, int $id, int $installmentId)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['remarks' => 'required|string|max:500']);

        return response()->json($this->service->waiveInstallment(
            $id, $installmentId, $data['remarks'], $this->tenant($request), $request->user()
        ));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage loans');
    }
}
