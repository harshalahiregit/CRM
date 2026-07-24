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
    public function __construct(private SalaryComponentRepository $repo)
    {
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

        $component = HrSalaryComponent::create([...$data, 'tenant_id' => $tenantId]);
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
        $this->assertCalculationRules(array_merge($component->only(['calculation_type', 'amount_value', 'percentage_value']), $data));

        $component->update($data);
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

    /** Trim + drop the value irrelevant to the chosen calculation type. */
    private function normalise(array $data): array
    {
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (isset($data['code'])) {
            $data['code'] = trim($data['code']);
        }

        if (($data['calculation_type'] ?? null) === 'Percentage') {
            $data['amount_value'] = null;
        } elseif (($data['calculation_type'] ?? null) === 'Fixed') {
            $data['percentage_value'] = null;
            $data['based_on'] = null;
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
