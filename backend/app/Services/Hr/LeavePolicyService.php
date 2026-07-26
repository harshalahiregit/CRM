<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrGrade;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeaveType;
use App\Models\User;
use App\Repositories\Hr\LeaveRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Leave Policies (Leave Phase 1). Tenant-unique name; optional Grade/Designation
 * from Organization Setup (reused). Maps leave types with per-type allocations.
 * Never hard-deleted — deactivate to retire.
 */
class LeavePolicyService
{
    public function __construct(private LeaveRepository $repo)
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
        $types = $this->cleanTypes($data['leave_types'] ?? [], $tenantId);

        $policy = DB::transaction(function () use ($data, $tenantId, $types, $actor) {
            $policy = HrLeavePolicy::create([...$this->attrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
            $this->syncTypes($policy, $types);

            return $policy;
        });
        $policy->recordAudit('Leave Policy Created', $actor, null, ['name' => $policy->name, 'types' => count($types)]);
        $this->log('Leave policy created', $tenantId, $policy->id);

        return $this->show($policy->id, $tenantId);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $policy = $this->find($id, $tenantId);
        if (array_key_exists('name', $data)) {
            $this->assertUniqueName($data['name'], $tenantId, $policy->id);
        }
        $this->assertRefs($data, $tenantId);
        $types = array_key_exists('leave_types', $data) ? $this->cleanTypes($data['leave_types'], $tenantId) : null;

        DB::transaction(function () use ($policy, $data, $types, $actor) {
            $policy->update([...$this->attrs($data), 'updated_by' => $actor?->id]);
            if ($types !== null) {
                $policy->policyTypes()->delete();
                $this->syncTypes($policy, $types);
            }
        });
        $policy->recordAudit('Leave Policy Updated', $actor, null, ['name' => $policy->name]);

        return $this->show($id, $tenantId);
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $policy = $this->find($id, $tenantId);
        $policy->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $policy->recordAudit($active ? 'Leave Policy Activated' : 'Leave Policy Deactivated', $actor);

        return $this->show($id, $tenantId);
    }

    /* ── Validation + helpers ─────────────────────────────── */
    private function assertUniqueName(?string $name, int $tenantId, ?int $ignoreId = null): void
    {
        if (! trim((string) $name)) {
            throw new BusinessException('Policy name is required.');
        }
        $exists = HrLeavePolicy::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("A leave policy named “{$name}” already exists.");
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
    }

    /** Keep only mappings whose leave type belongs to this tenant. */
    private function cleanTypes(array $rows, int $tenantId): array
    {
        $valid = HrLeaveType::where('tenant_id', $tenantId)->pluck('id')->all();
        $clean = [];
        foreach ($rows as $r) {
            $tid = (int) ($r['leave_type_id'] ?? 0);
            if (! in_array($tid, $valid, true)) {
                continue;
            }
            $clean[$tid] = [
                'leave_type_id'       => $tid,
                'yearly_allocation'   => (float) ($r['yearly_allocation'] ?? 0),
                'carry_forward_limit' => (float) ($r['carry_forward_limit'] ?? 0),
            ];
        }

        return array_values($clean);
    }

    private function syncTypes(HrLeavePolicy $policy, array $types): void
    {
        foreach ($types as $t) {
            $policy->policyTypes()->create($t);
        }
    }

    private function attrs(array $d): array
    {
        $attrs = array_filter([
            'name'        => $d['name'] ?? null,
            'applies_to'  => $d['applies_to'] ?? null,
            'description' => $d['description'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('grade_id', $d))       { $attrs['grade_id'] = $d['grade_id'] ?: null; }
        if (array_key_exists('designation_id', $d)) { $attrs['designation_id'] = $d['designation_id'] ?: null; }
        foreach (['probation_allowed', 'notice_period_allowed', 'weekends_count', 'holidays_count', 'half_day_allowed', 'negative_balance_allowed', 'is_active'] as $b) {
            if (array_key_exists($b, $d)) {
                $attrs[$b] = (bool) $d[$b];
            }
        }

        return $attrs;
    }

    private function present(HrLeavePolicy $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'applies_to' => $p->applies_to,
            'grade_id' => $p->grade_id, 'grade_name' => $p->grade?->name,
            'designation_id' => $p->designation_id, 'designation_name' => $p->designation?->name,
            'probation_allowed' => $p->probation_allowed, 'notice_period_allowed' => $p->notice_period_allowed,
            'weekends_count' => $p->weekends_count, 'holidays_count' => $p->holidays_count,
            'half_day_allowed' => $p->half_day_allowed, 'negative_balance_allowed' => $p->negative_balance_allowed,
            'description' => $p->description, 'is_active' => $p->is_active,
            'leave_types' => $p->policyTypes->map(fn ($t) => [
                'leave_type_id' => $t->leave_type_id, 'name' => $t->leaveType?->name, 'code' => $t->leaveType?->code,
                'category' => $t->leaveType?->category,
                'yearly_allocation' => (float) $t->yearly_allocation, 'carry_forward_limit' => (float) $t->carry_forward_limit,
            ])->all(),
        ];
    }

    private function find(int $id, int $tenantId): HrLeavePolicy
    {
        $policy = $this->repo->findPolicy($id, $tenantId);
        if (! $policy) {
            throw new BusinessException('Leave policy not found', 404);
        }

        return $policy;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
