<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrTrainingCategory;
use App\Models\User;
use App\Repositories\Hr\TrainingRepository;
use Illuminate\Support\Facades\Log;

/**
 * Training Categories (L&D Phase 1). Tenant-unique name + code (case-insensitive).
 * Never hard-deleted — deactivate to retire. Tenant-scoped, audited.
 */
class TrainingCategoryService
{
    public function __construct(private TrainingRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->categories($tenantId, $f)->map(fn ($c) => $this->present($c))->all(),
            'stats' => $this->repo->stats(HrTrainingCategory::class, $tenantId),
        ];
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $this->assertUnique('name', $data['name'] ?? null, $tenantId);
        $this->assertUnique('code', $data['code'] ?? null, $tenantId);

        $category = HrTrainingCategory::create([...$this->attrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
        $category->recordAudit('Training Category Created', $actor, null, ['name' => $category->name]);
        $this->log('Training category created', $tenantId, $category->id);

        return $this->present($category);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $category = $this->find($id, $tenantId);
        if (array_key_exists('name', $data)) {
            $this->assertUnique('name', $data['name'], $tenantId, $category->id);
        }
        if (array_key_exists('code', $data)) {
            $this->assertUnique('code', $data['code'], $tenantId, $category->id);
        }
        $category->update([...$this->attrs($data), 'updated_by' => $actor?->id]);
        $category->recordAudit('Training Category Updated', $actor, null, ['name' => $category->name]);

        return $this->present($category->fresh());
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $category = $this->find($id, $tenantId);
        $category->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $category->recordAudit($active ? 'Training Category Activated' : 'Training Category Deactivated', $actor);

        return $this->present($category->fresh());
    }

    private function assertUnique(string $field, ?string $value, int $tenantId, ?int $ignoreId = null): void
    {
        if (! trim((string) $value)) {
            throw new BusinessException(ucfirst($field).' is required.');
        }
        $exists = HrTrainingCategory::where('tenant_id', $tenantId)
            ->whereRaw("LOWER($field) = ?", [mb_strtolower(trim($value))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("A training category with this $field already exists.");
        }
    }

    private function attrs(array $d): array
    {
        $attrs = array_filter([
            'name'        => $d['name'] ?? null,
            'code'        => $d['code'] ?? null,
            'description' => $d['description'] ?? null,
        ], fn ($v) => $v !== null);
        if (array_key_exists('is_active', $d)) {
            $attrs['is_active'] = (bool) $d['is_active'];
        }

        return $attrs;
    }

    private function present(HrTrainingCategory $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'code' => $c->code,
            'description' => $c->description, 'is_active' => $c->is_active,
        ];
    }

    private function find(int $id, int $tenantId): HrTrainingCategory
    {
        $category = $this->repo->findCategory($id, $tenantId);
        if (! $category) {
            throw new BusinessException('Training category not found', 404);
        }

        return $category;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
