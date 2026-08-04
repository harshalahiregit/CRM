<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrInvestmentDeclaration;
use App\Models\Hr\HrInvestmentDeclarationItem;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Support\Hr\FinancialYear;
use App\Support\Hr\TaxSections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Investment declarations — what an employee claims, and what payroll verified.
 *
 * Lifecycle: Draft → Submitted → Verified | Rejected.
 *
 * Only a VERIFIED declaration reduces tax. That is the single most important rule
 * here: acting on an unverified claim under-deducts all year and hands the
 * employee a bill in March. Draft and Submitted are visible to the TDS engine but
 * contribute nothing, and the payslip says why.
 *
 * A submitted declaration is locked to the employee — payroll edits verified
 * amounts, never declared ones, so the claim itself survives as evidence.
 */
class InvestmentDeclarationService
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function fyStartMonth(int $tenantId): int
    {
        return (int) $this->settings->get($tenantId, 'payroll', 'fy_start_month', 4);
    }

    /** The financial year label to default to. */
    public function currentFy(int $tenantId): string
    {
        return FinancialYear::forDate(now(), $this->fyStartMonth($tenantId))->label();
    }

    public function list(int $tenantId, array $filters = []): array
    {
        $q = HrInvestmentDeclaration::forTenant($tenantId)->with(['employee:id,name,employee_code,department', 'items']);

        if (! empty($filters['financial_year'])) {
            $q->where('financial_year', $filters['financial_year']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['regime'])) {
            $q->where('regime', $filters['regime']);
        }
        if (! empty($filters['employee_id'])) {
            $q->where('employee_id', (int) $filters['employee_id']);
        }

        return $q->orderByDesc('financial_year')->orderBy('status')
            ->get()->map(fn ($d) => $this->present($d))->all();
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId), full: true);
    }

    /**
     * The declaration for one employee/year, creating an empty Draft if none exists.
     *
     * Creating on read keeps the UI simple — the form always has something to bind
     * to — and an empty Draft changes no tax figure, so it is safe.
     */
    public function forEmployee(int $employeeId, int $tenantId, ?string $fy = null): array
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }
        $fy ??= $this->currentFy($tenantId);

        $declaration = HrInvestmentDeclaration::forTenant($tenantId)
            ->where('employee_id', $employeeId)->where('financial_year', $fy)->with('items')->first();

        $declaration ??= HrInvestmentDeclaration::create([
            'tenant_id' => $tenantId, 'employee_id' => $employeeId, 'financial_year' => $fy,
            'regime' => HrInvestmentDeclaration::NEW, 'status' => HrInvestmentDeclaration::DRAFT,
        ]);

        return $this->present($declaration->load('items'), full: true);
    }

    /**
     * Save a draft: regime, previous-employer figures, HRA inputs and items.
     *
     * Items are replaced wholesale rather than diffed — the payload IS the claim,
     * and a partial merge would leave deleted lines behind.
     */
    public function save(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $declaration = $this->find($id, $tenantId);
        $this->assertEditable($declaration);

        DB::transaction(function () use ($declaration, $data, $tenantId, $actor) {
            $declaration->update(array_filter([
                'regime'                   => $data['regime'] ?? null,
                'previous_employer_income' => $data['previous_employer_income'] ?? null,
                'previous_employer_tds'    => $data['previous_employer_tds'] ?? null,
                'previous_employer_pf'     => $data['previous_employer_pf'] ?? null,
                'previous_employer_pt'     => $data['previous_employer_pt'] ?? null,
                'hra'                      => $data['hra'] ?? null,
                'remarks'                  => $data['remarks'] ?? null,
                'updated_by'               => $actor?->id,
            ], fn ($v) => $v !== null));

            if (array_key_exists('items', $data)) {
                $declaration->items()->delete();
                foreach ($data['items'] as $item) {
                    if (! TaxSections::exists((string) ($item['section'] ?? ''))) {
                        throw new BusinessException("Unknown deduction section: {$item['section']}");
                    }
                    HrInvestmentDeclarationItem::create([
                        'tenant_id'       => $tenantId,
                        'declaration_id'  => $declaration->id,
                        'section'         => $item['section'],
                        'particulars'     => $item['particulars'] ?? null,
                        'declared_amount' => max(0, (float) ($item['declared_amount'] ?? 0)),
                        'proof_submitted' => (bool) ($item['proof_submitted'] ?? false),
                        'remarks'         => $item['remarks'] ?? null,
                    ]);
                }
            }

            $this->recalculateTotals($declaration);
        });

        $declaration->recordAudit('Declaration Saved', $actor);

        return $this->present($this->find($id, $tenantId), full: true);
    }

    public function submit(int $id, int $tenantId, ?User $actor = null): array
    {
        $declaration = $this->find($id, $tenantId);
        $this->assertEditable($declaration);

        if ($declaration->items->isEmpty() && ! $declaration->previous_employer_income && ! ($declaration->hra['rent_paid_annual'] ?? null)) {
            throw new BusinessException('Nothing to submit — add at least one investment, HRA rent, or previous employer income.');
        }

        $declaration->update([
            'status' => HrInvestmentDeclaration::SUBMITTED,
            'submitted_at' => now(), 'updated_by' => $actor?->id,
        ]);
        $declaration->recordAudit('Declaration Submitted', $actor);
        $this->log('Declaration submitted', $tenantId, $declaration->id);

        return $this->present($this->find($id, $tenantId), full: true);
    }

    /**
     * Verify: payroll records what each line is actually worth after seeing proof.
     *
     * `verified_amount` may be lower than declared (partial proof) or zero
     * (rejected). Anything not named in the payload defaults to the declared
     * figure — verification is an exception list, not a re-entry of everything.
     */
    public function verify(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $declaration = $this->find($id, $tenantId);

        if ($declaration->status !== HrInvestmentDeclaration::SUBMITTED) {
            throw new BusinessException('Only a submitted declaration can be verified.');
        }

        DB::transaction(function () use ($declaration, $data, $actor) {
            $verified = collect($data['items'] ?? [])->keyBy('id');

            foreach ($declaration->items as $item) {
                $row = $verified->get($item->id);
                $item->update([
                    'verified_amount' => $row && array_key_exists('verified_amount', $row)
                        ? max(0, (float) $row['verified_amount'])
                        : (float) $item->declared_amount,
                    'remarks' => $row['remarks'] ?? $item->remarks,
                ]);
            }

            $declaration->update([
                'status' => HrInvestmentDeclaration::VERIFIED,
                'verified_at' => now(), 'verified_by' => $actor?->id, 'updated_by' => $actor?->id,
                'remarks' => $data['remarks'] ?? $declaration->remarks,
            ]);

            $this->recalculateTotals($declaration->load('items'));
        });

        $declaration->recordAudit('Declaration Verified', $actor, null,
            ['verified_total' => $declaration->fresh()->verified_total]);
        $this->log('Declaration verified', $tenantId, $declaration->id);

        return $this->present($this->find($id, $tenantId), full: true);
    }

    public function reject(int $id, string $remarks, int $tenantId, ?User $actor = null): array
    {
        $declaration = $this->find($id, $tenantId);

        if ($declaration->status !== HrInvestmentDeclaration::SUBMITTED) {
            throw new BusinessException('Only a submitted declaration can be rejected.');
        }

        $declaration->update([
            'status' => HrInvestmentDeclaration::REJECTED,
            'remarks' => $remarks, 'updated_by' => $actor?->id,
        ]);
        $declaration->recordAudit('Declaration Rejected', $actor, $remarks);

        return $this->present($this->find($id, $tenantId), full: true);
    }

    /** Send a verified or rejected declaration back to Draft so it can be revised. */
    public function reopen(int $id, int $tenantId, ?User $actor = null): array
    {
        $declaration = $this->find($id, $tenantId);

        if ($declaration->status === HrInvestmentDeclaration::DRAFT) {
            throw new BusinessException('This declaration is already open for editing.');
        }

        $declaration->update([
            'status' => HrInvestmentDeclaration::DRAFT,
            'submitted_at' => null, 'verified_at' => null, 'verified_by' => null,
            'updated_by' => $actor?->id,
        ]);
        $declaration->recordAudit('Declaration Reopened', $actor);

        return $this->present($this->find($id, $tenantId), full: true);
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    private function recalculateTotals(HrInvestmentDeclaration $declaration): void
    {
        $items = $declaration->items()->get();

        $declaration->update([
            'declared_total' => round((float) $items->sum('declared_amount'), 2),
            'verified_total' => round((float) $items->sum(fn ($i) => (float) ($i->verified_amount ?? 0)), 2),
        ]);
    }

    private function assertEditable(HrInvestmentDeclaration $declaration): void
    {
        if ($declaration->status === HrInvestmentDeclaration::VERIFIED) {
            throw new BusinessException('A verified declaration is locked. Reopen it to make changes.');
        }
        if ($declaration->status === HrInvestmentDeclaration::SUBMITTED) {
            throw new BusinessException('This declaration is submitted and awaiting verification.');
        }
    }

    private function find(int $id, int $tenantId): HrInvestmentDeclaration
    {
        $declaration = HrInvestmentDeclaration::forTenant($tenantId)->with(['items', 'employee:id,name,employee_code,department'])->find($id);
        if (! $declaration) {
            throw new BusinessException('Declaration not found', 404);
        }

        return $declaration;
    }

    private function present(HrInvestmentDeclaration $d, bool $full = false): array
    {
        $out = [
            'id'              => $d->id,
            'employee_id'     => $d->employee_id,
            'employee_name'   => $d->employee?->name,
            'employee_code'   => $d->employee?->employee_code,
            'department'      => $d->employee?->department,
            'financial_year'  => $d->financial_year,
            'regime'          => $d->regime,
            'status'          => $d->status,
            'declared_total'  => (float) $d->declared_total,
            'verified_total'  => (float) $d->verified_total,
            'counts_for_tax'  => $d->countsForTax(),
            'submitted_at'    => optional($d->submitted_at)->toIso8601String(),
            'verified_at'     => optional($d->verified_at)->toIso8601String(),
        ];

        if ($full) {
            $out += [
                'previous_employer_income' => $d->previous_employer_income !== null ? (float) $d->previous_employer_income : null,
                'previous_employer_tds'    => $d->previous_employer_tds !== null ? (float) $d->previous_employer_tds : null,
                'previous_employer_pf'     => $d->previous_employer_pf !== null ? (float) $d->previous_employer_pf : null,
                'previous_employer_pt'     => $d->previous_employer_pt !== null ? (float) $d->previous_employer_pt : null,
                'hra'     => $d->hra ?: null,
                'remarks' => $d->remarks,
                'items'   => $d->items->map(fn ($i) => [
                    'id'              => $i->id,
                    'section'         => $i->section,
                    'section_label'   => TaxSections::label($i->section),
                    'particulars'     => $i->particulars,
                    'declared_amount' => (float) $i->declared_amount,
                    'verified_amount' => $i->verified_amount !== null ? (float) $i->verified_amount : null,
                    'proof_submitted' => (bool) $i->proof_submitted,
                    'remarks'         => $i->remarks,
                ])->all(),
            ];
        }

        return $out;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
