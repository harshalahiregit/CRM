<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrLeaveType;
use App\Models\User;
use App\Repositories\Hr\LeaveRepository;
use Illuminate\Support\Facades\Log;

/**
 * Leave Types master (Leave Phase 1). Tenant-unique name + code, category-validated,
 * carry-forward capped by the yearly limit. Never hard-deleted — deactivate to retire.
 */
class LeaveTypeService
{
    public function __construct(private LeaveRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->types($tenantId, $f)->all(),
            'stats' => $this->repo->typeStats($tenantId),
        ];
    }

    public function create(array $data, int $tenantId, ?User $actor = null): HrLeaveType
    {
        $this->validate($data, $tenantId);
        $type = HrLeaveType::create([...$this->attrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
        $type->recordAudit('Leave Type Created', $actor, null, ['code' => $type->code, 'category' => $type->category]);
        $this->log('Leave type created', $tenantId, $type->id);

        return $type;
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): HrLeaveType
    {
        $type = $this->find($id, $tenantId);
        $this->validate($data, $tenantId, $type);
        $type->update([...$this->attrs($data), 'updated_by' => $actor?->id]);
        $type->recordAudit('Leave Type Updated', $actor, null, ['code' => $type->code]);

        return $type->fresh();
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): HrLeaveType
    {
        $type = $this->find($id, $tenantId);
        $type->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $type->recordAudit($active ? 'Leave Type Activated' : 'Leave Type Deactivated', $actor);

        return $type->fresh();
    }

    /* ── Validation ───────────────────────────────────────── */
    private function validate(array $data, int $tenantId, ?HrLeaveType $existing = null): void
    {
        $category = $data['category'] ?? $existing?->category;
        if (! $category) {
            throw new BusinessException('Category is required.');
        }
        if (! in_array($category, HrLeaveType::CATEGORIES, true)) {
            throw new BusinessException('Invalid leave category.');
        }
        if (array_key_exists('yearly_limit', $data) && (float) $data['yearly_limit'] < 0) {
            throw new BusinessException('Yearly limit must be zero or more.');
        }

        // Carry forward cannot exceed the yearly limit.
        $yearly = (float) ($data['yearly_limit'] ?? $existing?->yearly_limit ?? 0);
        $maxCf  = (float) ($data['max_carry_forward'] ?? $existing?->max_carry_forward ?? 0);
        if ($maxCf > $yearly) {
            throw new BusinessException('Max carry-forward cannot exceed the yearly limit.');
        }

        if (array_key_exists('name', $data)) {
            $this->assertUnique('name', $data['name'], $tenantId, $existing?->id, 'A leave type');
        }
        if (array_key_exists('code', $data)) {
            $this->assertUnique('code', $data['code'], $tenantId, $existing?->id, 'A leave type code');
        }
    }

    private function assertUnique(string $column, ?string $value, int $tenantId, ?int $ignoreId, string $label): void
    {
        $exists = HrLeaveType::where('tenant_id', $tenantId)
            ->whereRaw("LOWER($column) = ?", [mb_strtolower(trim((string) $value))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("{$label} “{$value}” already exists.");
        }
    }

    private function attrs(array $d): array
    {
        $attrs = array_filter([
            'name'        => $d['name'] ?? null,
            'code'        => $d['code'] ?? null,
            'category'    => $d['category'] ?? null,
            'color'       => $d['color'] ?? null,
            'description' => $d['description'] ?? null,
        ], fn ($v) => $v !== null);

        // Booleans + numerics are handled explicitly so `false`/`0` aren't dropped.
        foreach (['paid', 'carry_forward', 'requires_attachment', 'requires_approval', 'is_active'] as $b) {
            if (array_key_exists($b, $d)) {
                $attrs[$b] = (bool) $d[$b];
            }
        }
        foreach (['yearly_limit', 'max_carry_forward'] as $n) {
            if (array_key_exists($n, $d)) {
                $attrs[$n] = (float) $d[$n];
            }
        }

        return $attrs;
    }

    private function find(int $id, int $tenantId): HrLeaveType
    {
        $type = $this->repo->findType($id, $tenantId);
        if (! $type) {
            throw new BusinessException('Leave type not found', 404);
        }

        return $type;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
