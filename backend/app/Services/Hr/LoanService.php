<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrLoanInstallment;
use App\Models\Hr\HrLoanType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Employee loans and salary advances.
 *
 * An advance is a loan whose TYPE has `is_advance` set — one instalment, normally
 * interest-free. There is no second module: the schedule, the approval workflow
 * and the payroll deduction are shared, so a fix lands in one place.
 *
 * The schedule is generated at DISBURSEMENT and frozen. Approving agrees the terms;
 * disbursing is when money moved and repayment can legitimately start. Deducting
 * from an approved-but-undisbursed loan would take repayment for money the employee
 * never received.
 */
class LoanService
{
    public function __construct(private LoanEligibilityService $eligibility)
    {
    }

    /* ── Loan types ───────────────────────────────────────────────────── */

    public function types(int $tenantId, array $filters = []): array
    {
        $q = HrLoanType::forTenant($tenantId);

        if (isset($filters['is_advance']) && $filters['is_advance'] !== '') {
            $q->where('is_advance', filter_var($filters['is_advance'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $q->orderBy('name')->get()->map(fn ($t) => $this->presentType($t))->all();
    }

    public function saveType(?int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $attrs = array_filter([
            'name' => $data['name'] ?? null, 'code' => $data['code'] ?? null,
            'max_amount' => $data['max_amount'] ?? null,
            'max_tenure_months' => $data['max_tenure_months'] ?? null,
            'interest_rate' => $data['interest_rate'] ?? null,
            'description' => $data['description'] ?? null,
        ], fn ($v) => $v !== null);

        foreach (['is_advance', 'requires_approval', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $attrs[$flag] = (bool) $data[$flag];
            }
        }

        if ($id) {
            $type = $this->findType($id, $tenantId);
            $type->update($attrs + ['updated_by' => $actor?->id]);
        } else {
            $type = HrLoanType::create($attrs + ['tenant_id' => $tenantId, 'created_by' => $actor?->id]);
        }

        $type->recordAudit($id ? 'Loan Type Updated' : 'Loan Type Created', $actor, null, ['name' => $type->name]);

        return $this->presentType($type->fresh());
    }

    public function deleteType(int $id, int $tenantId, ?User $actor = null): void
    {
        $type = $this->findType($id, $tenantId);

        if (HrEmployeeLoan::forTenant($tenantId)->where('loan_type_id', $id)->exists()) {
            throw new BusinessException('Loans exist under this type. Deactivate it instead of deleting it.');
        }

        $type->recordAudit('Loan Type Deleted', $actor, null, ['name' => $type->name]);
        $type->delete();
    }

    /* ── Loans ────────────────────────────────────────────────────────── */

    public function list(int $tenantId, array $filters = []): array
    {
        $q = HrEmployeeLoan::forTenant($tenantId)
            ->with(['employee:id,name,employee_code,department', 'loanType:id,name,is_advance']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['employee_id'])) {
            $q->where('employee_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['loan_type_id'])) {
            $q->where('loan_type_id', (int) $filters['loan_type_id']);
        }
        if (isset($filters['is_advance']) && $filters['is_advance'] !== '') {
            $advance = filter_var($filters['is_advance'], FILTER_VALIDATE_BOOLEAN);
            $q->whereHas('loanType', fn ($t) => $t->where('is_advance', $advance));
        }

        return $q->orderByDesc('id')->get()->map(fn ($l) => $this->present($l))->all();
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId), full: true);
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => HrEmployeeLoan::forTenant($tenantId);

        return [
            'pending_approval' => (clone $base())->where('status', HrEmployeeLoan::SUBMITTED)->count(),
            'active'           => (clone $base())->where('status', HrEmployeeLoan::DISBURSED)->count(),
            'closed'           => (clone $base())->where('status', HrEmployeeLoan::CLOSED)->count(),
            'total_outstanding' => round((float) (clone $base())->where('status', HrEmployeeLoan::DISBURSED)->sum('outstanding'), 2),
            'total_disbursed'  => round((float) (clone $base())->whereIn('status', [HrEmployeeLoan::DISBURSED, HrEmployeeLoan::CLOSED])->sum('principal'), 2),
        ];
    }

    /**
     * Create or update a Draft.
     *
     * The type's ceilings are enforced here rather than at approval, so an
     * impossible request is refused while the requester is still looking at it.
     */
    public function save(?int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $type = $this->findType((int) ($data['loan_type_id'] ?? 0), $tenantId);

        $employee = HrEmployee::where('tenant_id', $tenantId)->find((int) ($data['employee_id'] ?? 0));
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        $principal = round((float) ($data['principal'] ?? 0), 2);
        if ($principal <= 0) {
            throw new BusinessException('Enter the amount being lent.');
        }
        if ($type->max_amount !== null && $principal > (float) $type->max_amount) {
            throw new BusinessException("{$type->name} is capped at ".number_format((float) $type->max_amount, 2).'.');
        }

        // An advance is repaid in one go by definition; anything else would make it
        // a loan wearing an advance's name.
        $tenure = $type->is_advance ? 1 : max(1, (int) ($data['tenure_months'] ?? 1));
        if (! $type->is_advance && $type->max_tenure_months !== null && $tenure > (int) $type->max_tenure_months) {
            throw new BusinessException("{$type->name} allows at most {$type->max_tenure_months} month(s).");
        }

        $rate = $type->is_advance ? 0.0 : round((float) ($data['interest_rate'] ?? $type->interest_rate ?? 0), 3);

        $schedule = $this->buildSchedule($principal, $rate, $tenure);

        // Affordability, judged on the total EMI burden. Checked here rather than
        // only at approval so an unaffordable request is refused while the person
        // entering it is still looking at the figures.
        $check = $this->eligibility->evaluate($employee->id, $tenantId, $schedule['emi'], (int) $id);
        if ($check['blocks']) {
            throw new BusinessException($check['message']);
        }

        $attrs = [
            'employee_id'   => $employee->id,
            'loan_type_id'  => $type->id,
            'principal'     => $principal,
            'interest_rate' => $rate,
            'tenure_months' => $tenure,
            'emi'           => $schedule['emi'],
            'total_payable' => $schedule['total_payable'],
            'outstanding'   => $schedule['total_payable'],
            'start_period'  => $data['start_period'] ?? null,
            'purpose'       => $data['purpose'] ?? null,
            'remarks'       => $data['remarks'] ?? null,
        ];

        if ($id) {
            $loan = $this->find($id, $tenantId);
            if ($loan->status !== HrEmployeeLoan::DRAFT) {
                throw new BusinessException('Only a draft loan can be edited.');
            }
            $loan->update($attrs + ['updated_by' => $actor?->id]);
        } else {
            $loan = HrEmployeeLoan::create($attrs + [
                'tenant_id' => $tenantId, 'status' => HrEmployeeLoan::DRAFT,
                'total_repaid' => 0, 'created_by' => $actor?->id,
            ]);
        }

        $loan->recordAudit($id ? 'Loan Updated' : 'Loan Requested', $actor, null, [
            'employee' => $employee->name, 'principal' => $principal,
        ]);

        return $this->present($loan->fresh(['employee', 'loanType']), full: true);
    }

    public function submit(int $id, int $tenantId, ?User $actor = null): array
    {
        $loan = $this->find($id, $tenantId);
        $this->assertStatus($loan, [HrEmployeeLoan::DRAFT], 'Only a draft loan can be submitted.');

        // A type that needs no approval goes straight to Approved — otherwise a
        // queue builds up that nobody is expected to action.
        $next = $loan->loanType?->requires_approval === false
            ? HrEmployeeLoan::APPROVED : HrEmployeeLoan::SUBMITTED;

        $loan->update([
            'status' => $next, 'submitted_at' => now(),
            'approved_at' => $next === HrEmployeeLoan::APPROVED ? now() : null,
            'updated_by' => $actor?->id,
        ]);
        $loan->recordAudit($next === HrEmployeeLoan::APPROVED ? 'Loan Auto-Approved' : 'Loan Submitted', $actor);

        return $this->present($loan->fresh(['employee', 'loanType']), full: true);
    }

    public function approve(int $id, int $tenantId, ?User $actor = null): array
    {
        $loan = $this->find($id, $tenantId);
        $this->assertStatus($loan, [HrEmployeeLoan::SUBMITTED], 'Only a submitted loan can be approved.');

        $loan->update([
            'status' => HrEmployeeLoan::APPROVED,
            'approved_at' => now(), 'approved_by' => $actor?->id, 'updated_by' => $actor?->id,
        ]);
        $loan->recordAudit('Loan Approved', $actor);
        $this->log('Loan approved', $tenantId, $loan->id);

        return $this->present($loan->fresh(['employee', 'loanType']), full: true);
    }

    public function reject(int $id, string $remarks, int $tenantId, ?User $actor = null): array
    {
        $loan = $this->find($id, $tenantId);
        $this->assertStatus($loan, [HrEmployeeLoan::SUBMITTED], 'Only a submitted loan can be rejected.');

        $loan->update(['status' => HrEmployeeLoan::REJECTED, 'remarks' => $remarks, 'updated_by' => $actor?->id]);
        $loan->recordAudit('Loan Rejected', $actor, $remarks);

        return $this->present($loan->fresh(['employee', 'loanType']), full: true);
    }

    /**
     * Disburse: money has moved, so freeze the schedule and start repayment.
     *
     * The schedule is written ONCE here. Regenerating it later would rewrite an
     * agreed EMI whenever a rate or a type ceiling changed.
     */
    public function disburse(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $loan = $this->find($id, $tenantId);
        $this->assertStatus($loan, [HrEmployeeLoan::APPROVED], 'Only an approved loan can be disbursed.');

        // Re-checked at the last moment: salary or other loans may have changed
        // between approval and disbursement, and this is the point money moves.
        $check = $this->eligibility->evaluate((int) $loan->employee_id, $tenantId, (float) $loan->emi, $loan->id);
        if ($check['blocks']) {
            throw new BusinessException($check['message']);
        }

        $disbursedOn = Carbon::parse($data['disbursed_on'] ?? now());
        // Repayment starts the month after disbursement unless told otherwise —
        // deducting in the same month would collect before the money was useful.
        $startPeriod = $data['start_period'] ?? $loan->start_period
            ?? $disbursedOn->copy()->addMonth()->format('Y-m');

        if (! preg_match('/^\d{4}-\d{2}$/', $startPeriod)) {
            throw new BusinessException('The first deduction period must look like 2026-04.');
        }

        DB::transaction(function () use ($loan, $disbursedOn, $startPeriod, $tenantId, $actor) {
            $loan->installments()->delete();   // a re-disbursal must not stack schedules

            $schedule = $this->buildSchedule(
                (float) $loan->principal, (float) $loan->interest_rate, (int) $loan->tenure_months
            );

            $period = Carbon::createFromFormat('Y-m', $startPeriod)->startOfMonth();

            foreach ($schedule['rows'] as $row) {
                HrLoanInstallment::create([
                    'tenant_id' => $tenantId, 'loan_id' => $loan->id,
                    'sequence' => $row['sequence'], 'period' => $period->format('Y-m'),
                    'amount' => $row['amount'],
                    'principal_component' => $row['principal'], 'interest_component' => $row['interest'],
                    'balance_after' => $row['balance_after'], 'status' => HrLoanInstallment::PENDING,
                ]);
                $period->addMonth();
            }

            $loan->update([
                'status' => HrEmployeeLoan::DISBURSED,
                'disbursed_on' => $disbursedOn->toDateString(),
                'start_period' => $startPeriod,
                'emi' => $schedule['emi'], 'total_payable' => $schedule['total_payable'],
                'outstanding' => $schedule['total_payable'], 'total_repaid' => 0,
                'loan_number' => $loan->loan_number ?: $this->loanNumber($loan, $tenantId),
                'updated_by' => $actor?->id,
            ]);
        });

        $loan->recordAudit('Loan Disbursed', $actor, null, [
            'from_period' => $startPeriod, 'installments' => $loan->tenure_months,
        ]);
        $this->log('Loan disbursed', $tenantId, $loan->id);

        return $this->present($loan->fresh(['employee', 'loanType', 'installments']), full: true);
    }

    /** Close a loan early. Remaining instalments are skipped, not deleted. */
    public function close(int $id, string $remarks, int $tenantId, ?User $actor = null): array
    {
        $loan = $this->find($id, $tenantId);
        $this->assertStatus($loan, [HrEmployeeLoan::DISBURSED], 'Only a disbursed loan can be closed.');

        DB::transaction(function () use ($loan, $remarks, $actor) {
            $loan->installments()->where('status', HrLoanInstallment::PENDING)
                ->update(['status' => HrLoanInstallment::SKIPPED, 'remarks' => $remarks]);

            $loan->update([
                'status' => HrEmployeeLoan::CLOSED, 'outstanding' => 0,
                'closed_at' => now(), 'remarks' => $remarks, 'updated_by' => $actor?->id,
            ]);
        });

        $loan->recordAudit('Loan Closed', $actor, $remarks);

        return $this->present($loan->fresh(['employee', 'loanType', 'installments']), full: true);
    }

    public function cancel(int $id, int $tenantId, ?User $actor = null): array
    {
        $loan = $this->find($id, $tenantId);
        $this->assertStatus($loan, [HrEmployeeLoan::DRAFT, HrEmployeeLoan::SUBMITTED, HrEmployeeLoan::APPROVED],
            'A disbursed loan cannot be cancelled — close it instead.');

        $loan->update(['status' => HrEmployeeLoan::CANCELLED, 'updated_by' => $actor?->id]);
        $loan->recordAudit('Loan Cancelled', $actor);

        return $this->present($loan->fresh(['employee', 'loanType']), full: true);
    }

    /** Waive a single instalment — it stops being due without touching the rest. */
    public function waiveInstallment(int $loanId, int $installmentId, string $remarks, int $tenantId, ?User $actor = null): array
    {
        $loan = $this->find($loanId, $tenantId);
        $installment = $loan->installments()->find($installmentId);

        if (! $installment) {
            throw new BusinessException('Instalment not found', 404);
        }
        if ($installment->status === HrLoanInstallment::DEDUCTED) {
            throw new BusinessException('That instalment has already been deducted by payroll.');
        }

        $installment->update(['status' => HrLoanInstallment::WAIVED, 'remarks' => $remarks]);
        $this->refreshOutstanding($loan);
        $loan->recordAudit('Instalment Waived', $actor, $remarks, ['sequence' => $installment->sequence]);

        return $this->present($loan->fresh(['employee', 'loanType', 'installments']), full: true);
    }

    /* ── Schedule maths ───────────────────────────────────────────────── */

    /**
     * Reducing-balance amortisation.
     *
     * With a zero rate this degrades to an even split, which is the advance case.
     * The FINAL instalment absorbs the rounding drift so the schedule sums exactly
     * to the total payable — otherwise a few paise linger forever and the loan
     * never closes.
     */
    public function buildSchedule(float $principal, float $annualRate, int $months): array
    {
        $months = max(1, $months);
        $rows = [];

        if ($annualRate <= 0) {
            $emi = round($principal / $months, 2);
            $balance = $principal;

            for ($i = 1; $i <= $months; $i++) {
                $amount = $i === $months ? round($balance, 2) : $emi;
                $balance = round($balance - $amount, 2);
                $rows[] = ['sequence' => $i, 'amount' => $amount, 'principal' => $amount,
                           'interest' => 0.0, 'balance_after' => max(0.0, $balance)];
            }

            return ['emi' => $emi, 'total_payable' => round($principal, 2), 'total_interest' => 0.0, 'rows' => $rows];
        }

        $r = $annualRate / 12 / 100;
        $factor = pow(1 + $r, $months);
        $emi = round($principal * $r * $factor / ($factor - 1), 2);

        $balance = $principal;
        $total = 0.0;

        for ($i = 1; $i <= $months; $i++) {
            $interest = round($balance * $r, 2);
            $principalPart = round($emi - $interest, 2);
            $amount = $emi;

            if ($i === $months) {
                // Last one settles whatever is actually left.
                $principalPart = round($balance, 2);
                $amount = round($principalPart + $interest, 2);
            }

            $balance = round($balance - $principalPart, 2);
            $total += $amount;

            $rows[] = ['sequence' => $i, 'amount' => $amount, 'principal' => $principalPart,
                       'interest' => $interest, 'balance_after' => max(0.0, $balance)];
        }

        return [
            'emi' => $emi,
            'total_payable' => round($total, 2),
            'total_interest' => round($total - $principal, 2),
            'rows' => $rows,
        ];
    }

    /** Preview a schedule without saving — powers the "what would this cost?" panel. */
    public function previewSchedule(float $principal, float $rate, int $months, ?string $startPeriod = null): array
    {
        $schedule = $this->buildSchedule($principal, $rate, $months);

        if ($startPeriod && preg_match('/^\d{4}-\d{2}$/', $startPeriod)) {
            $period = Carbon::createFromFormat('Y-m', $startPeriod)->startOfMonth();
            foreach ($schedule['rows'] as $i => $row) {
                $schedule['rows'][$i]['period'] = $period->format('Y-m');
                $period->addMonth();
            }
        }

        return $schedule;
    }

    /* ── Internals ────────────────────────────────────────────────────── */

    /** Recompute outstanding from the schedule — the instalments are the truth. */
    public function refreshOutstanding(HrEmployeeLoan $loan): void
    {
        $installments = $loan->installments()->get();

        $repaid = round((float) $installments->where('status', HrLoanInstallment::DEDUCTED)
            ->sum(fn ($i) => (float) ($i->deducted_amount ?? $i->amount)), 2);

        $outstanding = round((float) $installments->where('status', HrLoanInstallment::PENDING)->sum('amount'), 2);

        $loan->update(['total_repaid' => $repaid, 'outstanding' => $outstanding]);

        // Nothing left to collect and it was actually being repaid → close it.
        if ($outstanding <= 0 && $loan->status === HrEmployeeLoan::DISBURSED) {
            $loan->update(['status' => HrEmployeeLoan::CLOSED, 'closed_at' => now()]);
            $loan->recordAudit('Loan Fully Repaid');
        }
    }

    private function loanNumber(HrEmployeeLoan $loan, int $tenantId): string
    {
        $prefix = $loan->loanType?->is_advance ? 'ADV' : 'LOAN';
        $seq = HrEmployeeLoan::forTenant($tenantId)->whereNotNull('loan_number')->count() + 1;

        return sprintf('%s-%s-%04d', $prefix, now()->format('Y'), $seq);
    }

    private function assertStatus(HrEmployeeLoan $loan, array $allowed, string $message): void
    {
        if (! in_array($loan->status, $allowed, true)) {
            throw new BusinessException($message);
        }
    }

    private function findType(int $id, int $tenantId): HrLoanType
    {
        $type = HrLoanType::forTenant($tenantId)->find($id);
        if (! $type) {
            throw new BusinessException('Loan type not found', 404);
        }

        return $type;
    }

    private function find(int $id, int $tenantId): HrEmployeeLoan
    {
        $loan = HrEmployeeLoan::forTenant($tenantId)
            ->with(['employee:id,name,employee_code,department', 'loanType', 'installments'])->find($id);
        if (! $loan) {
            throw new BusinessException('Loan not found', 404);
        }

        return $loan;
    }

    private function presentType(HrLoanType $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'code' => $t->code,
            'is_advance' => (bool) $t->is_advance,
            'max_amount' => $t->max_amount !== null ? (float) $t->max_amount : null,
            'max_tenure_months' => $t->max_tenure_months,
            'interest_rate' => $t->interest_rate !== null ? (float) $t->interest_rate : null,
            'requires_approval' => (bool) $t->requires_approval,
            'description' => $t->description, 'is_active' => (bool) $t->is_active,
        ];
    }

    private function present(HrEmployeeLoan $l, bool $full = false): array
    {
        $out = [
            'id' => $l->id, 'loan_number' => $l->loan_number,
            'employee_id' => $l->employee_id, 'employee_name' => $l->employee?->name,
            'employee_code' => $l->employee?->employee_code, 'department' => $l->employee?->department,
            'loan_type_id' => $l->loan_type_id, 'loan_type' => $l->loanType?->name,
            'is_advance' => (bool) $l->loanType?->is_advance,
            'principal' => (float) $l->principal, 'interest_rate' => (float) $l->interest_rate,
            'tenure_months' => (int) $l->tenure_months, 'emi' => (float) $l->emi,
            'total_payable' => (float) $l->total_payable, 'total_repaid' => (float) $l->total_repaid,
            'outstanding' => (float) $l->outstanding,
            'start_period' => $l->start_period,
            'disbursed_on' => optional($l->disbursed_on)->toDateString(),
            'status' => $l->status, 'purpose' => $l->purpose, 'remarks' => $l->remarks,
            'approved_at' => optional($l->approved_at)->toIso8601String(),
        ];

        if ($full) {
            // Live affordability against today's salary and other loans — a loan
            // approved months ago may no longer be comfortable.
            $out['eligibility'] = $this->eligibility->evaluate(
                (int) $l->employee_id, (int) $l->tenant_id, (float) $l->emi, $l->id
            );
            $out['installments'] = $l->installments->map(fn ($i) => [
                'id' => $i->id, 'sequence' => $i->sequence, 'period' => $i->period,
                'amount' => (float) $i->amount,
                'principal_component' => (float) $i->principal_component,
                'interest_component' => (float) $i->interest_component,
                'balance_after' => (float) $i->balance_after,
                'status' => $i->status,
                'deducted_amount' => $i->deducted_amount !== null ? (float) $i->deducted_amount : null,
                'deducted_on' => optional($i->deducted_on)->toDateString(),
                'payroll_record_id' => $i->payroll_record_id,
                'remarks' => $i->remarks,
            ])->all();
        }

        return $out;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
