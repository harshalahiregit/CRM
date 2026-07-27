<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrSalaryComponent;
use App\Models\User;
use App\Repositories\Hr\SalaryComponentRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Salary Components master (Payroll Phase 1).
 *
 * Owns validation (tenant-unique name + code, type/calc rules), the never-hard-delete
 * policy (deactivate instead), and the audit trail. No salary processing, payslip or
 * statutory-calculation logic lives here — that is deliberately deferred to later phases.
 */
class SalaryComponentService
{
    public function __construct(
        private SalaryComponentRepository $repo,
        private SalaryFormulaEngine $engine,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->repo->filtered($tenantId, $filters);
    }

    /** Grouped counts for the header cards (Earnings / Deductions / Benefits / Active). */
    public function stats(int $tenantId): array
    {
        $base = HrSalaryComponent::where('tenant_id', $tenantId);

        return [
            'total'      => (clone $base)->count(),
            'earnings'   => (clone $base)->where('type', 'Earning')->count(),
            'employer'   => (clone $base)->whereIn('type', ['Employer', 'Benefit'])->count(),
            'deductions' => (clone $base)->where('type', 'Deduction')->count(),
            'benefits'   => (clone $base)->where('type', 'Benefit')->count(),
            'active'     => (clone $base)->where('is_active', true)->count(),
        ];
    }

    public function create(array $data, int $tenantId, ?User $actor = null): HrSalaryComponent
    {
        $data = $this->normalise($data);
        $this->assertUniqueName($tenantId, $data['name']);
        $this->assertUniqueCode($tenantId, $data['code']);
        $this->assertCalculationRules($data);
        $this->assertFormula($data, $tenantId);

        $component = HrSalaryComponent::create([
            ...$data, 'tenant_id' => $tenantId,
            'created_by' => $actor?->id, 'updated_by' => $actor?->id,
        ]);
        $component->recordAudit('Salary Component Created', $actor, null, [
            'code' => $component->code, 'type' => $component->type,
        ]);
        $this->log('Salary component created', $tenantId, $component->id);

        return $component;
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): HrSalaryComponent
    {
        $component = $this->find($id, $tenantId);
        $data = $this->normalise($data);

        if (array_key_exists('name', $data)) {
            $this->assertUniqueName($tenantId, $data['name'], $component->id);
        }
        if (array_key_exists('code', $data)) {
            $this->assertUniqueCode($tenantId, $data['code'], $component->id);
        }
        $merged = array_merge($component->only(['calculation_type', 'amount_value', 'percentage_value', 'formula']), $data);
        $this->assertCalculationRules($merged);
        $this->assertFormula($merged, $tenantId, $component->id);

        $component->update([...$data, 'updated_by' => $actor?->id]);
        $component->recordAudit('Salary Component Updated', $actor, null, ['code' => $component->code]);
        $this->log('Salary component updated', $tenantId, $component->id);

        return $component->fresh();
    }

    /**
     * Activate / deactivate. Deactivation is how components are "retired" — they are
     * never hard-deleted, so any historical salary structure referencing them stays valid.
     */
    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): HrSalaryComponent
    {
        $component = $this->find($id, $tenantId);
        $component->update(['is_active' => $active]);
        $component->recordAudit($active ? 'Salary Component Activated' : 'Salary Component Deactivated', $actor, null, ['code' => $component->code]);
        $this->log('Salary component status changed', $tenantId, $component->id);

        return $component->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Validation helpers
    |--------------------------------------------------------------------------
    */
    private function find(int $id, int $tenantId): HrSalaryComponent
    {
        $component = $this->repo->findForTenant($id, $tenantId);
        if (! $component) {
            throw new BusinessException('Salary component not found', 404);
        }

        return $component;
    }

    /** Trim + drop the values irrelevant to the chosen calculation type. */
    private function normalise(array $data): array
    {
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        switch ($data['calculation_type'] ?? null) {
            case 'Percentage':
                $data['amount_value'] = null;
                $data['formula'] = null;
                break;
            case 'Fixed':
                $data['percentage_value'] = null;
                $data['based_on'] = null;
                $data['formula'] = null;
                break;
            case 'Formula':
                $data['amount_value'] = null;
                $data['percentage_value'] = null;
                $data['based_on'] = null;
                break;
            case 'Manual':
                // A manual component is entered per-structure/employee — no master value.
                $data['percentage_value'] = null;
                $data['based_on'] = null;
                $data['formula'] = null;
                break;
        }

        return $data;
    }

    private function assertCalculationRules(array $data): void
    {
        $calc = $data['calculation_type'] ?? null;
        if ($calc === 'Percentage' && ($data['percentage_value'] ?? null) === null) {
            throw new BusinessException('Percentage value is required for a percentage-based component.');
        }
        if ($calc === 'Fixed' && ($data['amount_value'] ?? null) === null) {
            throw new BusinessException('Amount value is required for a fixed component.');
        }
        if ($calc === 'Formula' && trim((string) ($data['formula'] ?? '')) === '') {
            throw new BusinessException('A formula is required for a formula-based component.');
        }
    }

    /** Validate a Formula component's expression against the tenant's component codes. */
    private function assertFormula(array $data, int $tenantId, ?int $ignoreId = null): void
    {
        if (($data['calculation_type'] ?? null) !== 'Formula') {
            return;
        }
        $formula = trim((string) ($data['formula'] ?? ''));
        if ($formula === '') {
            return; // assertCalculationRules already enforces presence
        }
        $codes = HrSalaryComponent::where('tenant_id', $tenantId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->pluck('code')->all();

        // Throws BusinessException on unknown reference or invalid syntax.
        $this->engine->validateFormula($formula, $codes);
    }

    private function assertUniqueName(int $tenantId, ?string $name, ?int $ignoreId = null): void
    {
        $name = trim((string) $name);
        $exists = HrSalaryComponent::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw new BusinessException("A component named “{$name}” already exists.");
        }
    }

    private function assertUniqueCode(int $tenantId, ?string $code, ?int $ignoreId = null): void
    {
        $code = trim((string) $code);
        $exists = HrSalaryComponent::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw new BusinessException("Component code “{$code}” is already in use.");
        }
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
