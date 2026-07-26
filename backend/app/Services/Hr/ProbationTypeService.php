<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrProbationType;
use App\Models\User;
use App\Repositories\Hr\ProbationRepository;
use Illuminate\Support\Facades\Log;

/**
 * Probation Types (Probation Phase 1). Tenant-unique code + name (case-insensitive).
 * Never hard-deleted — deactivate to retire. Tenant-scoped, audited.
 */
class ProbationTypeService
{
    public function __construct(private ProbationRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->types($tenantId, $f)->map(fn ($t) => $this->present($t))->all(),
            'stats' => $this->repo->typeStats($tenantId),
        ];
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $this->assertUnique('code', $data['code'] ?? null, $tenantId);
        $this->assertUnique('name', $data['name'] ?? null, $tenantId);

        $type = HrProbationType::create([...$this->attrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
        $type->recordAudit('Probation Type Created', $actor, null, ['name' => $type->name]);
        $this->log('Probation type created', $tenantId, $type->id);

        return $this->present($type->fresh());
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $type = $this->find($id, $tenantId);
        if (array_key_exists('code', $data)) {
            $this->assertUnique('code', $data['code'], $tenantId, $type->id);
        }
        if (array_key_exists('name', $data)) {
            $this->assertUnique('name', $data['name'], $tenantId, $type->id);
        }
        $type->update([...$this->attrs($data), 'updated_by' => $actor?->id]);
        $type->recordAudit('Probation Type Updated', $actor, null, ['name' => $type->name]);

        return $this->present($type->fresh());
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $type = $this->find($id, $tenantId);
        $type->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $type->recordAudit($active ? 'Probation Type Activated' : 'Probation Type Deactivated', $actor);

        return $this->present($type->fresh());
    }

    private function assertUnique(string $field, ?string $value, int $tenantId, ?int $ignoreId = null): void
    {
        if (! trim((string) $value)) {
            throw new BusinessException(ucfirst($field).' is required.');
        }
        $exists = HrProbationType::where('tenant_id', $tenantId)
            ->whereRaw("LOWER($field) = ?", [mb_strtolower(trim($value))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("A probation type with this $field already exists.");
        }
    }

    private function attrs(array $d): array
    {
        $attrs = array_filter([
            'code'        => $d['code'] ?? null,
            'name'        => $d['name'] ?? null,
            'description' => $d['description'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('default_duration_days', $d)) {
            $attrs['default_duration_days'] = max(0, (int) $d['default_duration_days']);
        }
        if (array_key_exists('max_extensions', $d)) {
            $attrs['max_extensions'] = max(0, (int) $d['max_extensions']);
        }
        foreach (['confirmation_required', 'review_required', 'extension_allowed', 'is_active'] as $b) {
            if (array_key_exists($b, $d)) {
                $attrs[$b] = (bool) $d[$b];
            }
        }

        return $attrs;
    }

    private function present(HrProbationType $t): array
    {
        return [
            'id' => $t->id, 'code' => $t->code, 'name' => $t->name,
            'default_duration_days' => $t->default_duration_days,
            'confirmation_required' => $t->confirmation_required, 'review_required' => $t->review_required,
            'extension_allowed' => $t->extension_allowed, 'max_extensions' => $t->max_extensions,
            'description' => $t->description, 'is_active' => $t->is_active,
        ];
    }

    private function find(int $id, int $tenantId): HrProbationType
    {
        $type = $this->repo->findType($id, $tenantId);
        if (! $type) {
            throw new BusinessException('Probation type not found', 404);
        }

        return $type;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
