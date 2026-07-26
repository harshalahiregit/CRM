<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrExitPolicy;
use App\Models\Hr\HrExitType;
use App\Models\Hr\HrGrade;
use App\Models\User;
use App\Repositories\Hr\ExitRepository;
use Illuminate\Support\Facades\Log;

/**
 * Exit Policies (Exit Phase 1). Tenant-unique name; optional Grade / Designation /
 * Department (reused from Organization Setup) and a default Exit Type — all
 * validated against the tenant. Never hard-deleted (deactivate to retire).
 */
class ExitPolicyService
{
    public function __construct(private ExitRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return $this->repo->policies($tenantId, $f)->map(fn ($p) => $this->present($p))->all();
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId));
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $this->assertUniqueName($data['name'] ?? null, $tenantId);
        $this->assertRefs($data, $tenantId);

        $policy = HrExitPolicy::create([...$this->attrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
        $policy->recordAudit('Exit Policy Created', $actor, null, ['name' => $policy->name]);
        $this->log('Exit policy created', $tenantId, $policy->id);

        return $this->show($policy->id, $tenantId);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $policy = $this->find($id, $tenantId);
        if (array_key_exists('name', $data)) {
            $this->assertUniqueName($data['name'], $tenantId, $policy->id);
        }
        $this->assertRefs($data, $tenantId);
        $policy->update([...$this->attrs($data), 'updated_by' => $actor?->id]);
        $policy->recordAudit('Exit Policy Updated', $actor, null, ['name' => $policy->name]);

        return $this->show($id, $tenantId);
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $policy = $this->find($id, $tenantId);
        $policy->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $policy->recordAudit($active ? 'Exit Policy Activated' : 'Exit Policy Deactivated', $actor);

        return $this->show($id, $tenantId);
    }

    /* ── Validation + helpers ─────────────────────────────── */
    private function assertUniqueName(?string $name, int $tenantId, ?int $ignoreId = null): void
    {
        if (! trim((string) $name)) {
            throw new BusinessException('Policy name is required.');
        }
        $exists = HrExitPolicy::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("An exit policy named “{$name}” already exists.");
        }
    }

    private function assertRefs(array $data, int $tenantId): void
    {
        if (! empty($data['grade_id']) && ! HrGrade::where('tenant_id', $tenantId)->where('id', $data['grade_id'])->exists()) {
            throw new BusinessException('Selected grade is invalid.');
        }
        if (! empty($data['designation_id']) && ! HrDesignation::where('tenant_id', $tenantId)->where('id', $data['designation_id'])->exists()) {
            throw new BusinessException('Selected designation is invalid.');
        }
        if (! empty($data['department_id']) && ! HrDepartment::where('tenant_id', $tenantId)->where('id', $data['department_id'])->exists()) {
            throw new BusinessException('Selected department is invalid.');
        }
        if (! empty($data['default_exit_type_id']) && ! HrExitType::where('tenant_id', $tenantId)->where('id', $data['default_exit_type_id'])->exists()) {
            throw new BusinessException('Selected default exit type is invalid.');
        }
    }

    private function attrs(array $d): array
    {
        $attrs = array_filter([
            'name'        => $d['name'] ?? null,
            'description' => $d['description'] ?? null,
        ], fn ($v) => $v !== null);

        foreach (['grade_id', 'designation_id', 'department_id', 'default_exit_type_id'] as $ref) {
            if (array_key_exists($ref, $d)) {
                $attrs[$ref] = $d[$ref] ?: null;
            }
        }
        if (array_key_exists('notice_days', $d)) {
            $attrs['notice_days'] = (int) $d['notice_days'];
        }
        foreach (['buyout_allowed', 'recovery_allowed', 'leave_encashment', 'gratuity_applicable', 'clearance_required', 'exit_interview_required', 'is_active'] as $b) {
            if (array_key_exists($b, $d)) {
                $attrs[$b] = (bool) $d[$b];
            }
        }

        return $attrs;
    }

    private function present(HrExitPolicy $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name,
            'grade_id' => $p->grade_id, 'grade_name' => $p->grade?->name,
            'designation_id' => $p->designation_id, 'designation_name' => $p->designation?->name,
            'department_id' => $p->department_id, 'department_name' => $p->department?->name,
            'default_exit_type_id' => $p->default_exit_type_id, 'default_exit_type' => $p->defaultExitType?->name,
            'notice_days' => $p->notice_days,
            'buyout_allowed' => $p->buyout_allowed, 'recovery_allowed' => $p->recovery_allowed,
            'leave_encashment' => $p->leave_encashment, 'gratuity_applicable' => $p->gratuity_applicable,
            'clearance_required' => $p->clearance_required, 'exit_interview_required' => $p->exit_interview_required,
            'description' => $p->description, 'is_active' => $p->is_active,
        ];
    }

    private function find(int $id, int $tenantId): HrExitPolicy
    {
        $policy = $this->repo->findPolicy($id, $tenantId);
        if (! $policy) {
            throw new BusinessException('Exit policy not found', 404);
        }

        return $policy;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
