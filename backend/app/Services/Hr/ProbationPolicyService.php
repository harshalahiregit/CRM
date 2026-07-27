<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrGrade;
use App\Models\Hr\HrProbationPolicy;
use App\Models\Hr\HrProbationType;
use App\Models\User;
use App\Repositories\Hr\ProbationRepository;
use Illuminate\Support\Facades\Log;

/**
 * Probation Policies (Probation Phase 1). Tenant-unique name; a mandatory
 * Probation Type plus optional Grade / Designation / Department from Organization
 * Setup — all validated against the tenant. Never hard-deleted (deactivate).
 * Tenant-scoped, audited.
 */
class ProbationPolicyService
{
    private const FREQUENCIES = ['Weekly', 'Monthly', 'Quarterly'];

    public function __construct(private ProbationRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->policies($tenantId, $f)->map(fn ($p) => $this->present($p))->all(),
            'stats' => $this->repo->policyStats($tenantId),
        ];
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId));
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $this->assertUniqueName($data['name'] ?? null, $tenantId);
        $this->assertRefs($data, $tenantId, true);

        $policy = HrProbationPolicy::create([...$this->attrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
        $policy->recordAudit('Probation Policy Created', $actor, null, ['name' => $policy->name]);
        $this->log('Probation policy created', $tenantId, $policy->id);

        return $this->show($policy->id, $tenantId);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $policy = $this->find($id, $tenantId);
        if (array_key_exists('name', $data)) {
            $this->assertUniqueName($data['name'], $tenantId, $policy->id);
        }
        $this->assertRefs($data, $tenantId, false);
        $policy->update([...$this->attrs($data), 'updated_by' => $actor?->id]);
        $policy->recordAudit('Probation Policy Updated', $actor, null, ['name' => $policy->name]);

        return $this->show($id, $tenantId);
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $policy = $this->find($id, $tenantId);
        $policy->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $policy->recordAudit($active ? 'Probation Policy Activated' : 'Probation Policy Deactivated', $actor);

        return $this->show($id, $tenantId);
    }

    /* ── Validation + helpers ─────────────────────────────── */

    private function assertUniqueName(?string $name, int $tenantId, ?int $ignoreId = null): void
    {
        if (! trim((string) $name)) {
            throw new BusinessException('Policy name is required.');
        }
        $exists = HrProbationPolicy::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("A probation policy named “{$name}” already exists.");
        }
    }

    private function assertRefs(array $data, int $tenantId, bool $creating): void
    {
        // Probation Type is mandatory on the policy.
        if ($creating || array_key_exists('probation_type_id', $data)) {
            if (empty($data['probation_type_id'])) {
                throw new BusinessException('A probation type is required.');
            }
            if (! HrProbationType::where('tenant_id', $tenantId)->where('id', $data['probation_type_id'])->exists()) {
                throw new BusinessException('Selected probation type is invalid.');
            }
        }
        if (! empty($data['department_id']) && ! HrDepartment::where('tenant_id', $tenantId)->where('id', $data['department_id'])->exists()) {
            throw new BusinessException('Selected department is invalid.');
        }
        if (! empty($data['designation_id']) && ! HrDesignation::where('tenant_id', $tenantId)->where('id', $data['designation_id'])->exists()) {
            throw new BusinessException('Selected designation is invalid.');
        }
        if (! empty($data['grade_id']) && ! HrGrade::where('tenant_id', $tenantId)->where('id', $data['grade_id'])->exists()) {
            throw new BusinessException('Selected grade is invalid.');
        }
    }

    private function attrs(array $d): array
    {
        $attrs = array_filter([
            'name' => $d['name'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('probation_type_id', $d)) {
            $attrs['probation_type_id'] = (int) $d['probation_type_id'];
        }
        foreach (['department_id', 'designation_id', 'grade_id'] as $ref) {
            if (array_key_exists($ref, $d)) {
                $attrs[$ref] = $d[$ref] ?: null;
            }
        }
        if (array_key_exists('review_frequency', $d)) {
            $attrs['review_frequency'] = in_array($d['review_frequency'], self::FREQUENCIES, true) ? $d['review_frequency'] : 'Monthly';
        }
        if (array_key_exists('notice_days', $d)) {
            $attrs['notice_days'] = max(0, (int) $d['notice_days']);
        }
        if (array_key_exists('extension_limit', $d)) {
            $attrs['extension_limit'] = max(0, (int) $d['extension_limit']);
        }
        foreach (['auto_confirmation', 'is_active'] as $b) {
            if (array_key_exists($b, $d)) {
                $attrs[$b] = (bool) $d[$b];
            }
        }

        return $attrs;
    }

    private function present(HrProbationPolicy $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name,
            'probation_type_id' => $p->probation_type_id, 'probation_type' => $p->probationType?->name,
            'department_id' => $p->department_id, 'department_name' => $p->department?->name,
            'designation_id' => $p->designation_id, 'designation_name' => $p->designation?->name,
            'grade_id' => $p->grade_id, 'grade_name' => $p->grade?->name,
            'review_frequency' => $p->review_frequency, 'notice_days' => $p->notice_days,
            'extension_limit' => $p->extension_limit, 'auto_confirmation' => $p->auto_confirmation,
            'is_active' => $p->is_active,
        ];
    }

    private function find(int $id, int $tenantId): HrProbationPolicy
    {
        $policy = $this->repo->findPolicy($id, $tenantId);
        if (! $policy) {
            throw new BusinessException('Probation policy not found', 404);
        }

        return $policy;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
