<?php

namespace App\Http\Controllers\Api\Hrm;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrReimbursement;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\AdvanceTierService;
use App\Services\Hr\EmployeeIdentityService;
use App\Services\Hr\ReimbursementService;
use App\Services\Shared\AttachmentService;
use App\Support\Hr\AdvanceStage;
use App\Support\Hr\ReimbursementStatus;
use App\Support\Hrm\HrmResponse;
use Illuminate\Http\Request;

/**
 * Expense claims and advances, in the app's shapes.
 *
 * Backed by the same services the CRM screens use, so a claim raised on a phone
 * and one raised in the browser are the same record with the same rules —
 * including the hold-and-answer exchange, which the app has no screen for but
 * whose outcome it must still display correctly.
 *
 * Their advance model reads twenty-five fields, several of which are computed
 * rather than stored: `advance_type_label`, `case_label`, `payroll_label`,
 * `can_upload_settlement`. Every one is sent, because an absent key renders
 * blank and a blank status label reads as "no status" rather than "unknown".
 */
class HrmClaimController extends Controller
{
    public function __construct(
        private EmployeeIdentityService $identity,
        private ReimbursementService $claims,
        private AdvanceService $advances,
        private AdvanceTierService $tiers,
        private AttachmentService $attachments,
    ) {
    }

    /* ── reimbursements ──────────────────────────────────────────────── */

    public function reimbursements(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $rows = HrReimbursement::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->with('attachments')
            ->orderByDesc('id')
            ->get();

        return HrmResponse::ok($rows->map(fn (HrReimbursement $c) => [
            'id'            => $c->id,
            'title'         => (string) $c->title,
            'description'   => (string) ($c->description ?? ''),
            // The figure that matters is what was approved, once there is one.
            'amount'        => $this->money($c->amount_approved ?? $c->amount_claimed),
            'expense_date'  => $this->date($c->expense_date),
            'receipt'       => $this->firstAttachmentUrl($c),
            'status'        => $this->claimStatus($c),
            'admin_remarks' => $this->claimRemarks($c),
            'created_at'    => $this->date($c->created_at),
        ])->values()->all());
    }

    public function submitReimbursement(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $data = $request->validate([
            'title'        => 'required|string|max:200',
            'amount'       => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'description'  => 'nullable|string|max:2000',
            'receipt'      => 'nullable|file|max:10240|mimes:pdf,png,jpg,jpeg,webp,heic',
        ]);

        $claim = $this->claims->submit($employee, [
            'title'          => $data['title'],
            'description'    => $data['description'] ?? null,
            'expense_date'   => $data['expense_date'],
            'amount_claimed' => $data['amount'],
        ], $request->user());

        if ($request->hasFile('receipt')) {
            $this->attachments->upload(
                HrReimbursement::class, $claim->id, (int) $claim->tenant_id,
                $request->file('receipt'), null, [], $request->user()
            );
        }

        return HrmResponse::ok([], 'Reimbursement submitted successfully');
    }

    /* ── advances ────────────────────────────────────────────────────── */

    public function myAdvances(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $rows = HrAdvance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->with(['attachments', 'settlements', 'employee:id,department'])
            ->orderByDesc('id')
            ->get();

        return HrmResponse::ok($rows->map(fn (HrAdvance $a) => $this->advancePayload($a))->values()->all());
    }

    public function submitAdvance(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $data = $request->validate([
            'advance_type'             => 'nullable|string|max:40',
            'category'                 => 'nullable|string|max:60',
            'department'               => 'nullable|string|max:120',
            'project_site'             => 'nullable|string|max:120',
            'purpose'                  => 'required|string|max:2000',
            'amount_requested'         => 'required|numeric|min:0.01',
            'required_date'            => 'nullable|date',
            'expected_settlement_date' => 'nullable|date',
            'attachment'               => 'nullable|file|max:10240|mimes:pdf,png,jpg,jpeg,webp,heic',
        ]);

        try {
            $advance = $this->advances->request($employee, $data, $request->user());
        } catch (BusinessException $e) {
            return HrmResponse::fail($e->getMessage());
        }

        if ($request->hasFile('attachment')) {
            $this->attachments->upload(
                HrAdvance::class, $advance->id, (int) $advance->tenant_id,
                $request->file('attachment'), null, [], $request->user()
            );
        }

        return HrmResponse::ok([], 'Advance request submitted successfully');
    }

    public function advanceDetail(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $data = $request->validate(['advance_id' => 'required']);

        // Scoped by employee as well as tenant: a guessed id must not return
        // somebody else's money.
        $advance = HrAdvance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->with(['attachments', 'settlements', 'employee:id,department'])
            ->find((int) $data['advance_id']);

        if (! $advance) {
            return HrmResponse::fail('That advance could not be found.');
        }

        return HrmResponse::ok($this->advancePayload($advance, withLedger: true));
    }

    public function submitSettlement(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $data = $request->validate([
            'advance_id'       => 'required',
            'actual_expense'   => 'required|numeric|min:0',
            'settlement_notes' => 'nullable|string|max:2000',
            'bills'            => 'nullable|array|max:10',
            'bills.*'          => 'file|max:10240|mimes:pdf,png,jpg,jpeg,webp,heic',
        ]);

        $advance = HrAdvance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->find((int) $data['advance_id']);

        if (! $advance) {
            return HrmResponse::fail('That advance could not be found.');
        }

        try {
            $settlement = $this->advances->submitSettlement($advance, $request->user(), [
                'actual_expense' => $data['actual_expense'],
                'notes'          => $data['settlement_notes'] ?? null,
            ]);
        } catch (BusinessException $e) {
            return HrmResponse::fail($e->getMessage());
        }

        // The app posts bills[] — one file today, an array by contract.
        foreach ((array) $request->file('bills', []) as $bill) {
            $this->attachments->upload(
                \App\Models\Hr\HrAdvanceSettlement::class, $settlement->id,
                (int) $settlement->tenant_id, $bill, null, [], $request->user()
            );
        }

        return HrmResponse::ok([], 'Settlement submitted successfully');
    }

    /** The running account of one advance. */
    public function advanceLedger(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $data = $request->validate(['advance_id' => 'required']);

        $advance = HrAdvance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->with('settlements')
            ->find((int) $data['advance_id']);

        if (! $advance) {
            return HrmResponse::fail('That advance could not be found.');
        }

        return HrmResponse::ok($this->ledger($advance));
    }

    /**
     * What payroll needs to know about this person's advances.
     *
     * Not the parked payroll module — this is only what is outstanding and what
     * each advance settled to, which the app shows on its own payroll screen.
     */
    public function payrollSummary(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $rows = HrAdvance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->with(['settlements', 'employee:id,department'])
            ->orderByDesc('id')
            ->get();

        $live = $rows->whereIn('status', [AdvanceStage::DISBURSED, AdvanceStage::SETTLEMENT_SUBMITTED]);

        return HrmResponse::ok([
            'total_outstanding' => $this->money($live->sum('disbursed_amount')),
            'has_active'        => $live->isNotEmpty(),
            'items'             => $rows->map(function (HrAdvance $a) {
                $s = $a->settlements->last();

                return [
                    'advance_id'          => $a->reference ?: (string) $a->id,
                    'advance_type'        => (string) ($a->advance_type ?? ''),
                    'status'              => (string) $a->status,
                    'amount_requested'    => $this->money($a->amount_requested),
                    'amount_approved'     => $this->money($a->amount_approved),
                    'amount_modified'     => $this->amountModified($a),
                    'modified_reason'     => $this->modifiedReason($a),
                    'settlement_case'     => $s ? $this->settlementCase($s) : '',
                    'balance_return'      => $s ? $this->money($s->balance_return) : '',
                    'extra_reimbursement' => $s ? $this->money($s->extra_due) : '',
                    'payroll_label'       => $this->payrollLabel($a, $s),
                ];
            })->values()->all(),
        ]);
    }

    /* ── payload builders ────────────────────────────────────────────── */

    /** Every one of the twenty-five fields their AdvanceRequestData reads. */
    private function advancePayload(HrAdvance $a, bool $withLedger = false): array
    {
        $s = $a->settlements->last();

        return [
            'id'                       => $a->id,
            'advance_id'               => $a->reference ?: (string) $a->id,
            'advance_type'             => (string) ($a->advance_type ?? ''),
            'advance_type_label'       => $this->titleise($a->advance_type),
            'category'                 => (string) ($a->category ?? ''),
            // The value entered on the request, falling back to the employee's own
            // department when it was left blank — which is what the box being
            // optional means. Echoing the employee's unconditionally hid the fact
            // that a typed value was being thrown away.
            'department'               => (string) ($a->department ?: ($a->employee->department ?? '')),
            'project_site'             => (string) ($a->project_site ?? ''),
            'purpose'                  => (string) $a->purpose,
            'amount_requested'         => $this->money($a->amount_requested),
            'amount_approved'          => $this->money($a->amount_approved),
            'amount_modified'          => $this->amountModified($a),
            'amount_modified_reason'   => $this->modifiedReason($a),
            'required_date'            => $this->date($a->required_date),
            'expected_settlement_date' => $this->date($a->expected_settlement_date),
            'attachment'               => $this->firstAttachmentUrl($a),
            'status'                   => (string) $a->status,
            'rejection_reason'         => $this->declineReason($a),
            'has_disbursement'         => (bool) $a->disbursed_at,
            'disbursed_on'             => $this->date($a->disbursed_at),
            'payment_mode'             => (string) ($a->disbursement_mode ?? ''),
            'utr_reference'            => (string) ($a->disbursement_reference ?? ''),
            'settlement_status'        => $s ? (string) $s->status : '',
            // Only once the money is out and nothing is already under review.
            'can_upload_settlement'    => $a->status === AdvanceStage::DISBURSED,
            'settlement'               => $s ? [
                'actual_expense'             => $this->money($s->actual_expense),
                'balance_return'             => $this->money($s->balance_return),
                'settlement_case'            => $this->settlementCase($s),
                'case_label'                 => (string) $s->case_label,
                'status'                     => (string) $s->status,
                'reviewer_remarks'           => (string) ($s->review_remarks ?? ''),
                'extra_reimbursement_amount' => $this->money($s->extra_due),
            ] : null,
            'ledger'                   => $withLedger ? $this->ledger($a) : [],
            'created_at'               => $this->date($a->created_at),
        ];
    }

    /**
     * The running account: what went out, what came back.
     *
     * Built from the advance and its settlements rather than stored, because a
     * ledger derived from the facts cannot disagree with them.
     */
    private function ledger(HrAdvance $a): array
    {
        $entries = [];
        $balance = 0.0;

        if ($a->disbursed_at) {
            $balance += (float) $a->disbursed_amount;
            $entries[] = [
                'entry_date'       => $this->date($a->disbursed_at),
                'entry_type'       => 'disbursement',
                'entry_type_label' => 'Advance paid out',
                'debit'            => $this->money($a->disbursed_amount),
                'credit'           => '0',
                'running_balance'  => $this->money($balance),
                'description'      => trim('Paid by '.str_replace('_', ' ', (string) $a->disbursement_mode)
                    .($a->disbursement_reference ? ' · '.$a->disbursement_reference : '')),
            ];
        }

        foreach ($a->settlements as $s) {
            // A rejected settlement is history, not money: it moves nothing.
            $isAccepted = $s->status === \App\Models\Hr\HrAdvanceSettlement::ACCEPTED;

            if ($isAccepted) {
                $balance -= (float) $s->actual_expense;
            }

            $entries[] = [
                'entry_date'       => $this->date($s->reviewed_at ?? $s->created_at),
                'entry_type'       => 'settlement',
                'entry_type_label' => $isAccepted ? 'Settled' : 'Settlement '.$s->status,
                'debit'            => '0',
                'credit'           => $isAccepted ? $this->money($s->actual_expense) : '0',
                'running_balance'  => $this->money($balance),
                'description'      => (string) $s->case_label,
            ];
        }

        return $entries;
    }

    /* ── small conversions ───────────────────────────────────────────── */

    private function employee(Request $request): ?HrEmployee
    {
        return $this->identity->employeeFor($request->user());
    }

    /** Strings throughout: their models read String? and print blanks for null. */
    private function money($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $trimmed = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        // rtrim eats "0.00" down to an empty string; zero is a real amount.
        return $trimmed === '' ? '0' : $trimmed;
    }

    private function date($value): string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d') : '';
    }

    private function titleise(?string $v): string
    {
        return $v ? ucwords(str_replace('_', ' ', $v)) : '';
    }

    /** Only when it actually differs — otherwise the app shows a pointless change. */
    private function amountModified(HrAdvance $a): string
    {
        if ($a->amount_approved === null) {
            return '';
        }

        return abs((float) $a->amount_approved - (float) $a->amount_requested) > 0.005
            ? $this->money($a->amount_approved)
            : '';
    }

    /** The reason lives on the thread, which is where it was recorded. */
    private function modifiedReason(HrAdvance $a): string
    {
        $event = $a->messages()->where('event_type', 'amount_changed')->latest('id')->first();

        return (string) ($event->meta['reason'] ?? '');
    }

    private function declineReason(HrAdvance $a): string
    {
        $event = $a->messages()->where('event_type', 'declined')->latest('id')->first();

        return (string) ($event->meta['reason'] ?? '');
    }

    private function settlementCase($s): string
    {
        return match (true) {
            (float) $s->extra_due > 0      => 'more_spent',
            (float) $s->balance_return > 0 => 'less_spent',
            default                        => 'exact',
        };
    }

    private function payrollLabel(HrAdvance $a, $s): string
    {
        if (! $a->disbursed_at) {
            return '';
        }

        if (! $s || $s->status !== \App\Models\Hr\HrAdvanceSettlement::ACCEPTED) {
            return 'Outstanding '.$this->money($a->disbursed_amount);
        }

        if ((float) $s->balance_return > 0) {
            return 'Recover '.$this->money($s->balance_return);
        }

        if ((float) $s->extra_due > 0) {
            return 'Reimburse '.$this->money($s->extra_due);
        }

        return 'Settled';
    }

    private function claimStatus(HrReimbursement $c): string
    {
        // on_hold is a CRM state the app has no word for. Showing it as pending
        // is honest — from the employee's side it is still waiting — and the
        // remark below carries what was actually asked.
        return $c->status === ReimbursementStatus::ON_HOLD ? 'pending' : (string) $c->status;
    }

    /** What an approver said, whichever way it went. */
    private function claimRemarks(HrReimbursement $c): string
    {
        $event = $c->messages()
            ->whereIn('event_type', ['held', 'declined', 'approved', 'amount_changed'])
            ->where('kind', 'event')
            ->latest('id')
            ->first();

        return (string) ($event->body ?? '');
    }

    /**
     * The first attachment, as something the app can actually open.
     *
     * A SIGNED, temporary URL. Their models read this as a plain string and give
     * it to an image widget, which sends no Authorization header — so a normal
     * protected route would answer 401 and the receipt would show as a broken
     * image. The signature carries the permission instead, and expires.
     */
    private function firstAttachmentUrl($subject): string
    {
        $file = $subject->attachments->first();

        if (! $file) {
            return '';
        }

        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'hrm.file', now()->addDays(7), ['attachment' => $file->id]
        );
    }
}

