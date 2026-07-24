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
    public function __construct(private SalaryStructureRepository $repo)
    {
    }

    public function list(int $tenantId, array $filters): array
    {
        return $this->repo->filtered($tenantId, $filters)
            ->map(fn ($s) => $this->present($s))
            ->all();
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId));
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
     * Resolve every line to a concrete amount. Fixed lines resolve directly;
     * percentage lines resolve against the (already-resolved) line they are based
     * on. Iterative so a percentage of a percentage still converges; anything
     * unresolvable (missing base) settles at 0. Returns [lineId => amount].
     */
    private function resolve($structure): array
    {
        $lines = $structure->lines;
        // A percentage line's `based_on` may reference the base line by either its
        // component name ("Basic Salary") or its code ("BASIC") — index both.
        $byKey = [];
        foreach ($lines as $line) {
            if ($line->component) {
                $byKey[mb_strtolower($line->component->name)] = $line;
                if ($line->component->code) {
                    $byKey[mb_strtolower($line->component->code)] = $line;
                }
            }
        }

        $resolved = [];
        for ($pass = 0; $pass < 12 && count($resolved) < $lines->count(); $pass++) {
            $progress = false;
            foreach ($lines as $line) {
                if (array_key_exists($line->id, $resolved) || ! $line->component) {
                    continue;
                }
                $comp = $line->component;

                if ($comp->calculation_type === 'Fixed') {
                    $resolved[$line->id] = (float) ($line->amount ?? $comp->amount_value ?? 0);
                    $progress = true;
                    continue;
                }

                // Percentage — resolve against its base line (matched by name or code).
                $baseName = mb_strtolower($line->based_on ?: ($comp->based_on ?: 'basic'));
                $baseLine = $byKey[$baseName] ?? null;
                if ($baseLine && array_key_exists($baseLine->id, $resolved)) {
                    $pct = (float) ($line->percentage ?? $comp->percentage_value ?? 0);
                    $resolved[$line->id] = round($pct / 100 * $resolved[$baseLine->id], 2);
                    $progress = true;
                }
            }
            if (! $progress) {
                break;
            }
        }

        // Unresolved (e.g. base not present) → 0, so totals stay well-defined.
        foreach ($lines as $line) {
            $resolved[$line->id] = $resolved[$line->id] ?? 0.0;
        }

        return $resolved;
    }

    /** Shape a structure into the API response with per-line computed amounts + totals. */
    private function present(HrSalaryStructure $structure): array
    {
        $resolved = $this->resolve($structure);
        $earnings = $benefits = $deductions = 0.0;

        $lines = $structure->lines->map(function ($line) use ($resolved, &$earnings, &$benefits, &$deductions) {
            $comp = $line->component;
            $amount = $resolved[$line->id] ?? 0.0;
            $type = $comp->type ?? null;

            if ($type === 'Earning')   { $earnings   += $amount; }
            if ($type === 'Benefit')   { $benefits   += $amount; }
            if ($type === 'Deduction') { $deductions += $amount; }

            return [
                'id'               => $line->id,
                'component_id'     => $line->component_id,
                'component_name'   => $comp->name ?? '(removed)',
                'code'             => $comp->code ?? null,
                'type'             => $type,
                'calculation_type' => $comp->calculation_type ?? null,
                'amount'           => $line->amount !== null ? (float) $line->amount : null,
                'percentage'       => $line->percentage !== null ? (float) $line->percentage : null,
                'based_on'         => $line->based_on ?: ($comp->based_on ?? null),
                'computed_amount'  => round($amount, 2),
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
                'gross_earnings'   => round($earnings, 2),
                'employer_benefits'=> round($benefits, 2),
                'deductions'       => round($deductions, 2),
                'ctc'              => round($earnings + $benefits, 2),   // cost to company
                'net_pay'          => round($earnings - $deductions, 2), // employee take-home
            ],
        ];
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
            $clean[] = [
                'component_id' => $cid,
                'amount'       => ($line['amount'] ?? '') === '' ? null : $line['amount'],
                'percentage'   => ($line['percentage'] ?? '') === '' ? null : $line['percentage'],
                'based_on'     => $line['based_on'] ?? null,
                'sort_order'   => $i,
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
