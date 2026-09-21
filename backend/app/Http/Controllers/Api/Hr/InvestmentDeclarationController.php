<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrApprovalAction;
use App\Models\Hr\HrInvestmentDeclaration;
use App\Services\Hr\Approval\ApprovalEngine;
use App\Services\Hr\Form16Service;
use App\Services\Hr\InvestmentDeclarationService;
use App\Support\Hr\Approval\ApprovalProcess;
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
        private ApprovalEngine $engine,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($this->tenant($request),
                $request->only(['financial_year', 'status', 'regime', 'employee_id']),
                $request->user()),
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
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    /** The employee's declaration for a year, creating an empty draft if needed. */
    public function forEmployee(Request $request, int $employeeId)
    {
        return response()->json($this->service->forEmployee(
            $employeeId, $this->tenant($request), $request->query('financial_year'), $request->user()
        ));
    }

    /**
     * HR-only for now, matching verify() below.
     *
     * A declaration decides how much tax is withheld — regime, HRA, 80C, previous
     * employer income all feed TDS — and this takes a declaration id, not "mine",
     * so ungated it let any signed-in staff member rewrite anybody's.
     *
     * An employee filling in their OWN declaration is the obvious next thing to
     * want, and it is deliberately not built here: it needs a /me route that
     * resolves the caller's declaration rather than accepting an id.
     */
    public function save(Request $request, int $id)
    {
        $this->assertCanManage($request);

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

    /** HR-only, like save(): submitting locks the declaration for verification. */
    public function submit(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json($this->service->submit($id, $this->tenant($request), $request->user()));
    }

    /**
     * Verify — through the configured ladder.
     *
     * Verification is not quite approval, and the difference matters here.
     * Verifying carries a PAYLOAD: the per-item verified_amount that overrides
     * what the employee declared, and the totals recalculated from it. It is
     * the act of checking proofs, not of granting a request.
     *
     * So on a multi-step ladder only the LAST rung's figures are applied. An
     * intermediate approver records that they are satisfied and the
     * declaration stays Submitted — which also means countsForTax() stays
     * false and nothing reaches TDS until the ladder finishes. Applying an
     * intermediate approver's amounts would be executing the final domain
     * action early, and would leave the question of whose figures win.
     */
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

        return $this->decide($request, $id, HrApprovalAction::APPROVED, $data['remarks'] ?? null, $data);
    }

    public function reject(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['remarks' => 'required|string|max:1000']);

        return $this->decide($request, $id, HrApprovalAction::REJECTED, $data['remarks'], []);
    }

    /**
     * One decision, through the engine, for both verbs.
     *
     * The three gates stay separate: assertCanManage() is the capability,
     * findForDecision() and the engine both apply the data scope, and the
     * ladder decides whether this declaration is waiting on this person. Only
     * the last rung calls the service, which still owns the item amounts, the
     * totals recalculation, the status transition and the audit line.
     *
     * reopen() is deliberately NOT routed here. Reopening un-decides a
     * declaration so it can be edited and resubmitted; it is an administrative
     * correction, not a rung. A reopened declaration that is submitted again
     * opens a FRESH round, because the previous round is closed — the same
     * behaviour variable earnings needed when an edited figure invalidated its
     * approval.
     */
    private function decide(Request $request, int $id, string $action, ?string $remarks, array $payload)
    {
        $tenantId = $this->tenant($request);
        $actor    = $request->user();

        $declaration = $this->service->findForDecision($id, $tenantId, $actor);
        abort_unless($declaration, 404, 'Declaration not found');

        /*
         | Anything other than Submitted is the service's business.
         |
         | Passed straight through so the existing messages — "only a submitted
         | declaration can be verified", "…can be rejected" — are what the user
         | sees. Both service methods already refuse a non-submitted
         | declaration, which is what stops a second decision running the final
         | action twice.
         */
        if ($declaration->status !== HrInvestmentDeclaration::SUBMITTED) {
            $approval = $this->engine->requestFor(
                $declaration,
                ApprovalProcess::INVESTMENT_DECLARATION,
                $tenantId,
                (int) $declaration->employee_id,
                (float) $declaration->declared_total,
            );
            $this->engine->supersede($approval);

            return response()->json(
                $action === HrApprovalAction::APPROVED
                    ? $this->service->verify($id, $payload, $tenantId, $actor)
                    : $this->service->reject($id, (string) $remarks, $tenantId, $actor)
            );
        }

        $approval = $this->engine->requestFor(
            $declaration,
            ApprovalProcess::INVESTMENT_DECLARATION,
            $tenantId,
            (int) $declaration->employee_id,
            (float) $declaration->declared_total,
        );

        $inspection = $this->engine->inspect($approval);
        if (! $inspection['resolvable']) {
            $this->engine->block($approval, $inspection['describe']);
            abort(409, 'This declaration cannot be verified yet: '.$inspection['describe'].'.');
        }

        $this->engine->assertMayDecide($approval, $actor);

        $result = $this->engine->decide($approval, $actor, $action, $remarks);

        if (! $result['final']) {
            // Still climbing. The declaration stays Submitted, so
            // countsForTax() is false and it reduces nobody's tax yet.
            return response()->json([
                'approval' => $this->approvalPayload($result['request']),
                'data'     => $this->service->show($id, $tenantId, $actor),
            ]);
        }

        $payloadOut = $action === HrApprovalAction::APPROVED
            ? $this->service->verify($id, $payload, $tenantId, $actor)
            : $this->service->reject($id, (string) $remarks, $tenantId, $actor);

        return response()->json($payloadOut + ['approval' => $this->approvalPayload($result['request'])]);
    }

    /** What the UI needs to draw the ladder's current position. */
    private function approvalPayload($approval): array
    {
        return [
            'state'        => $approval->state,
            'current_step' => $approval->current_step,
            'total_steps'  => count($approval->steps_snapshot ?: []),
            'steps'        => $approval->steps_snapshot ?: [],
        ];
    }

    public function reopen(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json($this->service->reopen($id, $this->tenant($request), $request->user()));
    }

    /* ── Form-16-ready data ───────────────────────────────────────────── */

    /**
     * Form 16 is an export of the whole tax position for one employee — gross,
     * every exemption, TDS deducted, PAN. The employee id comes from the URL, so
     * the scope is checked here for the same reason the declaration endpoints
     * check theirs. An export is a read, and this is the most complete read of
     * an employee's pay that the product offers.
     */
    public function form16(Request $request, int $employeeId)
    {
        $this->assertEmployeeInScope($request, $employeeId);

        return response()->json($this->form16->forEmployee(
            $employeeId, $this->tenant($request), $request->query('financial_year')
        ));
    }

    public function form16Years(Request $request, int $employeeId)
    {
        // Which years exist is itself a fact about that employee's service.
        $this->assertEmployeeInScope($request, $employeeId);

        return response()->json(['data' => $this->form16->availableYears($employeeId, $this->tenant($request))]);
    }

    private function assertEmployeeInScope(Request $request, int $employeeId): void
    {
        app(\App\Services\Auth\ScopeResolver::class)
            ->assertCanActOnEmployee($request->user(), $employeeId);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    /**
     * Wording widened from "verify" now that save() and submit() share this gate —
     * a refusal that says "verify" when somebody pressed Save sends them looking
     * for the wrong permission. Same authority, same status, no test pins it.
     */
    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage declarations');
    }
}
