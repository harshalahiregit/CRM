<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrInvestmentDeclaration;
use App\Services\Hr\Form16Service;
use App\Services\Hr\InvestmentDeclarationService;
use App\Support\Hr\TaxSections;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Investment declarations + Form-16-ready data. Thin: validate, delegate, return.
 *
 * Verification and rejection require HR-queue management — they decide what
 * reduces someone's tax. Saving and submitting a draft do not, so an employee can
 * maintain their own declaration.
 */
class InvestmentDeclarationController extends Controller
{
    public function __construct(
        private InvestmentDeclarationService $service,
        private Form16Service $form16,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($this->tenant($request),
                $request->only(['financial_year', 'status', 'regime', 'employee_id'])),
        ]);
    }

    /** Vocabulary + defaults the declaration form renders from. */
    public function meta(Request $request)
    {
        return response()->json([
            'sections'       => TaxSections::options(),
            'regimes'        => HrInvestmentDeclaration::REGIMES,
            'statuses'       => HrInvestmentDeclaration::STATUSES,
            'current_fy'     => $this->service->currentFy($this->tenant($request)),
            'fy_start_month' => $this->service->fyStartMonth($this->tenant($request)),
        ]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    /** The employee's declaration for a year, creating an empty draft if needed. */
    public function forEmployee(Request $request, int $employeeId)
    {
        return response()->json($this->service->forEmployee(
            $employeeId, $this->tenant($request), $request->query('financial_year')
        ));
    }

    public function save(Request $request, int $id)
    {
        $data = $request->validate([
            'regime'                   => ['nullable', Rule::in(HrInvestmentDeclaration::REGIMES)],
            'previous_employer_income' => 'nullable|numeric|min:0',
            'previous_employer_tds'    => 'nullable|numeric|min:0',
            'previous_employer_pf'     => 'nullable|numeric|min:0',
            'previous_employer_pt'     => 'nullable|numeric|min:0',
            'hra'                          => 'nullable|array',
            'hra.rent_paid_annual'         => 'nullable|numeric|min:0',
            'hra.metro'                    => 'nullable|boolean',
            'hra.months'                   => 'nullable|integer|min:1|max:12',
            'hra.landlord_pan'             => 'nullable|string|max:20',
            'items'                        => 'nullable|array',
            'items.*.section'              => ['required', Rule::in(array_merge(TaxSections::codes(), [TaxSections::HRA]))],
            'items.*.particulars'          => 'nullable|string|max:191',
            'items.*.declared_amount'      => 'required|numeric|min:0',
            'items.*.proof_submitted'      => 'nullable|boolean',
            'items.*.remarks'              => 'nullable|string|max:500',
            'remarks'                      => 'nullable|string|max:1000',
        ]);

        return response()->json($this->service->save($id, $data, $this->tenant($request), $request->user()));
    }

    public function submit(Request $request, int $id)
    {
        return response()->json($this->service->submit($id, $this->tenant($request), $request->user()));
    }

    public function verify(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'items'                   => 'nullable|array',
            'items.*.id'              => 'required|integer',
            'items.*.verified_amount' => 'nullable|numeric|min:0',
            'items.*.remarks'         => 'nullable|string|max:500',
            'remarks'                 => 'nullable|string|max:1000',
        ]);

        return response()->json($this->service->verify($id, $data, $this->tenant($request), $request->user()));
    }

    public function reject(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['remarks' => 'required|string|max:1000']);

        return response()->json($this->service->reject($id, $data['remarks'], $this->tenant($request), $request->user()));
    }

    public function reopen(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json($this->service->reopen($id, $this->tenant($request), $request->user()));
    }

    /* ── Form-16-ready data ───────────────────────────────────────────── */

    public function form16(Request $request, int $employeeId)
    {
        return response()->json($this->form16->forEmployee(
            $employeeId, $this->tenant($request), $request->query('financial_year')
        ));
    }

    public function form16Years(Request $request, int $employeeId)
    {
        return response()->json(['data' => $this->form16->availableYears($employeeId, $this->tenant($request))]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to verify declarations');
    }
}
