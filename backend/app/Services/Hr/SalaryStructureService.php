<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\User;
use App\Repositories\Hr\SalaryStructureRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Salary Structures master (Payroll Phase 2).
 *
 * Composes Salary Components into a named structure and computes the CTC
 * breakdown on read (nothing denormalised): fixed lines resolve directly,
 * percentage lines resolve against the amount of the line they are based on
 * (typically Basic). No employee assignment / payroll processing here.
 */
class SalaryStructureService
{
    public function __construct(
        private SalaryStructureRepository $repo,
        private SalaryFormulaEngine $engine,
    ) {
    }

    public function list(int $tenantId, array $filters): array
    {
        return $this->repo->filtered($tenantId, $filters)
            ->map(fn ($s) => $this->present($s))
            ->all();
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        $structure = $this->find($id, $tenantId);
        if ($actor) {
            $structure->recordAudit('Salary Structure Viewed', $actor);
        }

        return $this->present($structure);
    }

    public function stats(int $tenantId): array
    {
        $base = HrSalaryStructure::where('tenant_id', $tenantId);

        return [
            'total'  => (clone $base)->count(),
            'active' => (clone $base)->where('is_active', true)->count(),
        ];
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $this->assertUnique($tenantId, $data);
        $lines = $this->validateLines($data['lines'] ?? [], $tenantId);

        $structure = DB::transaction(function () use ($data, $tenantId, $lines) {
            $structure = HrSalaryStructure::create([
                'tenant_id'      => $tenantId,
                'name'           => trim($data['name']),
                'code'           => isset($data['code']) ? trim($data['code']) : null,
                'grade_id'       => $data['grade_id'] ?? null,
                'designation_id' => $data['designation_id'] ?? null,
                'description'    => $data['description'] ?? null,
                'is_active'      => $data['is_active'] ?? true,
            ]);
            $this->syncLines($structure, $lines);

            return $structure;
        });

        $this->persistTotals($structure);
        $structure->recordAudit('Salary Structure Created', $actor, null, ['name' => $structure->name, 'lines' => count($lines)]);
        $this->log('Salary structure created', $tenantId, $structure->id);

        return $this->present($this->find($structure->id, $tenantId));
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $structure = $this->find($id, $tenantId);
        $this->assertUnique($tenantId, $data, $structure->id);
        $lines = array_key_exists('lines', $data) ? $this->validateLines($data['lines'], $tenantId) : null;

        DB::transaction(function () use ($structure, $data, $lines) {
            $attrs = [];
            if (isset($data['name']))                      { $attrs['name'] = trim($data['name']); }
            if (array_key_exists('code', $data))           { $attrs['code'] = trim((string) $data['code']) ?: null; }
            if (array_key_exists('grade_id', $data))       { $attrs['grade_id'] = $data['grade_id'] ?: null; }
            if (array_key_exists('designation_id', $data)) { $attrs['designation_id'] = $data['designation_id'] ?: null; }
            if (array_key_exists('description', $data))    { $attrs['description'] = $data['description']; }
            if (array_key_exists('is_active', $data))      { $attrs['is_active'] = $data['is_active']; }
            if ($attrs) {
                $structure->update($attrs);
            }

            // Lines are replaced wholesale when provided (simplest correct semantics).
            if ($lines !== null) {
                $structure->lines()->delete();
                $this->syncLines($structure, $lines);
            }
        });

        $this->persistTotals($structure);
        $structure->recordAudit('Salary Structure Updated', $actor, null, ['name' => $structure->name]);
        $this->log('Salary structure updated', $tenantId, $structure->id);

        return $this->present($this->find($id, $tenantId));
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $structure = $this->find($id, $tenantId);
        $structure->update(['is_active' => $active]);
        $structure->recordAudit($active ? 'Salary Structure Activated' : 'Salary Structure Deactivated', $actor, null, ['name' => $structure->name]);
        $this->log('Salary structure status changed', $tenantId, $structure->id);

        return $this->present($this->find($id, $tenantId));
    }

    /*
    |--------------------------------------------------------------------------
    | CTC computation
    |--------------------------------------------------------------------------
    */

    /**
     * Build the engine input from a structure's lines. A line may override the
     * component's calculation (calculation_type / formula / amount / percentage /
     * based_on); when it doesn't, the component master value is used — so existing
     * structures resolve exactly as before.
     */
    private function engineItems(HrSalaryStructure $structure): array
    {
        return $structure->lines->map(function ($line) {
            $c = $line->component;

            return [
                'key'              => $line->id,
                'code'             => $c->code ?? ('CMP'.$line->id),
                'name'             => $c->name ?? '(removed)',
                'type'             => $c->type ?? 'Earning',
                'calculation_type' => $line->calculation_type ?: ($c->calculation_type ?? 'Fixed'),
                'amount'           => $line->amount ?? ($c->amount_value ?? null),
                'percentage'       => $line->percentage ?? ($c->percentage_value ?? null),
                'based_on'         => $line->based_on ?: ($c->based_on ?? null),
                'formula'          => $line->formula ?: ($c->formula ?? null),
                'sequence'         => $line->sort_order,
            ];
        })->all();
    }

    /** Compute + persist the denormalised monthly totals cache on the structure. */
    private function persistTotals(HrSalaryStructure $structure): void
    {
        $structure->load('lines.component');
        $bd = $this->engine->calculate($this->engineItems($structure))['breakdown'];
        $structure->update([
            'gross_salary'          => $bd['gross_salary']['monthly'],
            'employer_contribution' => $bd['employer_contribution']['monthly'],
            'monthly_ctc'           => $bd['ctc']['monthly'],
            'annual_ctc'            => $bd['ctc']['yearly'],
            'total_deduction'       => $bd['total_deduction']['monthly'],
            'net_salary'            => $bd['net_salary']['monthly'],
        ]);
    }

    /**
     * Shape a structure into the API response. Amounts come from the central
     * SalaryFormulaEngine (Fixed / Percentage / Formula / Manual, circular-safe). The
     * legacy `totals` block is preserved verbatim (engine-derived, so identical for
     * existing Fixed/Percentage structures) and a rich enterprise `breakdown` block
     * (Earnings→Gross→Employer→CTC→Deductions→Net, monthly + yearly) is added.
     */
    private function present(HrSalaryStructure $structure): array
    {
        $result = $this->engine->calculate($this->engineItems($structure));
        $byKey = $result['resolved'];       // line id => monthly amount
        $bd = $result['breakdown'];

        $lines = $structure->lines->map(function ($line) use ($byKey) {
            $comp = $line->component;
            $amount = round($byKey[$line->id] ?? 0.0, 2);

            return [
                'id'               => $line->id,
                'component_id'     => $line->component_id,
                'component_name'   => $comp->name ?? '(removed)',
                'code'             => $comp->code ?? null,
                'type'             => $comp->type ?? null,
                'calculation_type' => $line->calculation_type ?: ($comp->calculation_type ?? null),
                'amount'           => $line->amount !== null ? (float) $line->amount : null,
                'percentage'       => $line->percentage !== null ? (float) $line->percentage : null,
                'based_on'         => $line->based_on ?: ($comp->based_on ?? null),
                'formula'          => $line->formula ?: ($comp->formula ?? null),
                'taxable'          => (bool) ($comp->taxable ?? false),
                'pf_applicable'    => (bool) ($comp->pf_applicable ?? false),
                'esic_applicable'  => (bool) ($comp->esic_applicable ?? false),
                'computed_amount'  => $amount,
                'computed_yearly'  => round($amount * 12, 2),
                'sort_order'       => $line->sort_order,
            ];
        })->all();

        return [
            'id'               => $structure->id,
            'name'             => $structure->name,
            'code'             => $structure->code,
            'grade_id'         => $structure->grade_id,
            'grade_name'       => $structure->grade?->name,
            'designation_id'   => $structure->designation_id,
            'designation_name' => $structure->designation?->name,
            'description'      => $structure->description,
            'is_active'        => $structure->is_active,
            'lines'            => $lines,
            'totals'           => [
                'gross_earnings'   => $bd['gross_salary']['monthly'],
                'employer_benefits'=> $bd['employer_contribution']['monthly'],
                'deductions'       => $bd['total_deduction']['monthly'],
                'ctc'              => $bd['ctc']['monthly'],
                'net_pay'          => $bd['net_salary']['monthly'],
            ],
            'breakdown'        => $bd,
        ];
    }

    /**
     * Live preview for the Salary Builder — resolve a not-yet-saved set of lines and
     * return the enterprise breakdown, without persisting anything.
     */
    public function preview(array $lines, int $tenantId): array
    {
        $clean = $this->validateLines($lines, $tenantId);
        $comps = HrSalaryComponent::where('tenant_id', $tenantId)
            ->whereIn('id', collect($clean)->pluck('component_id'))->get()->keyBy('id');

        $items = [];
        foreach ($clean as $i => $l) {
            $c = $comps[$l['component_id']] ?? null;
            if (! $c) {
                continue;
            }
            $items[] = [
                'key'              => $i,
                'code'             => $c->code,
                'name'             => $c->name,
                'type'             => $c->type,
                'calculation_type' => $l['calculation_type'] ?: $c->calculation_type,
                'amount'           => $l['amount'] ?? $c->amount_value,
                'percentage'       => $l['percentage'] ?? $c->percentage_value,
                'based_on'         => $l['based_on'] ?: $c->based_on,
                'formula'          => $l['formula'] ?: $c->formula,
                'sequence'         => $i,
            ];
        }

        // Full result: `resolved` (per-line by index) for inline amounts + enterprise `breakdown`.
        return $this->engine->calculate($items);
    }

    /** Duplicate a structure (with its lines) under a fresh unique name. */
    public function duplicate(int $id, int $tenantId, ?User $actor = null): array
    {
        $src = $this->find($id, $tenantId);
        $name = $this->uniqueCopyName($tenantId, $src->name);

        $structure = DB::transaction(function () use ($src, $tenantId, $name, $actor) {
            $new = HrSalaryStructure::create([
                'tenant_id'      => $tenantId,
                'name'           => $name,
                'code'           => null,
                'grade_id'       => $src->grade_id,
                'designation_id' => $src->designation_id,
                'description'    => $src->description,
                'is_active'      => true,
                'created_by'     => $actor?->id,
                'updated_by'     => $actor?->id,
            ]);
            foreach ($src->lines as $l) {
                $new->lines()->create([
                    'component_id'     => $l->component_id,
                    'calculation_type' => $l->calculation_type,
                    'amount'           => $l->amount,
                    'percentage'       => $l->percentage,
                    'based_on'         => $l->based_on,
                    'formula'          => $l->formula,
                    'sort_order'       => $l->sort_order,
                ]);
            }

            return $new;
        });

        $this->persistTotals($structure);
        $structure->recordAudit('Salary Structure Duplicated', $actor, null, ['from' => $src->name, 'name' => $name]);
        $this->log('Salary structure duplicated', $tenantId, $structure->id);

        return $this->present($this->find($structure->id, $tenantId));
    }

    private function uniqueCopyName(int $tenantId, string $base): string
    {
        $candidate = "{$base} (Copy)";
        $n = 2;
        while (HrSalaryStructure::where('tenant_id', $tenantId)->whereRaw('LOWER(name) = ?', [mb_strtolower($candidate)])->exists()) {
            $candidate = "{$base} (Copy {$n})";
            $n++;
        }

        return $candidate;
    }

    /*
    |--------------------------------------------------------------------------
    | Validation helpers
    |--------------------------------------------------------------------------
    */
    private function find(int $id, int $tenantId): HrSalaryStructure
    {
        $structure = $this->repo->findForTenant($id, $tenantId);
        if (! $structure) {
            throw new BusinessException('Salary structure not found', 404);
        }

        return $structure;
    }

    private function assertUnique(int $tenantId, array $data, ?int $ignoreId = null): void
    {
        if (isset($data['name'])) {
            $exists = HrSalaryStructure::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
            if ($exists) {
                throw new BusinessException("A salary structure named “{$data['name']}” already exists.");
            }
        }
        if (! empty($data['code'])) {
            $exists = HrSalaryStructure::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($data['code']))])
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
            if ($exists) {
                throw new BusinessException("Structure code “{$data['code']}” is already in use.");
            }
        }
    }

    /**
     * Validate the incoming lines: at least one, every component must belong to
     * this tenant. Returns a clean, ordered array ready to persist.
     */
    private function validateLines(array $lines, int $tenantId): array
    {
        if (empty($lines)) {
            throw new BusinessException('A salary structure needs at least one component.');
        }

        $ids = collect($lines)->pluck('component_id')->filter()->unique();
        $valid = HrSalaryComponent::where('tenant_id', $tenantId)->whereIn('id', $ids)->pluck('id')->all();

        $clean = [];
        foreach (array_values($lines) as $i => $line) {
            $cid = $line['component_id'] ?? null;
            if (! $cid || ! in_array($cid, $valid)) {
                throw new BusinessException('One or more selected components are invalid for this tenant.');
            }
            $calc = $line['calculation_type'] ?? null;
            $clean[] = [
                'component_id'     => $cid,
                'calculation_type' => in_array($calc, HrSalaryComponent::CALC_TYPES, true) ? $calc : null,
                'amount'           => ($line['amount'] ?? '') === '' ? null : $line['amount'],
                'percentage'       => ($line['percentage'] ?? '') === '' ? null : $line['percentage'],
                'based_on'         => $line['based_on'] ?? null,
                'formula'          => ($line['formula'] ?? '') === '' ? null : $line['formula'],
                'sort_order'       => $i,
            ];
        }

        return $clean;
    }

    private function syncLines(HrSalaryStructure $structure, array $lines): void
    {
        foreach ($lines as $line) {
            $structure->lines()->create($line);
        }
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
