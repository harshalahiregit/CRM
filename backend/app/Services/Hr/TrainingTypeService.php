<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrTrainingType;
use App\Models\User;
use App\Repositories\Hr\TrainingRepository;
use Illuminate\Support\Facades\Log;

/**
 * Training Types (L&D Phase 1). Tenant-unique name + code; carries the delivery
 * mode and a default duration. Never hard-deleted — deactivate to retire.
 * Tenant-scoped, audited.
 */
class TrainingTypeService
{
    private const MODES = ['Online', 'Offline', 'Hybrid'];

    public function __construct(private TrainingRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->types($tenantId, $f)->map(fn ($t) => $this->present($t))->all(),
            'stats' => $this->repo->stats(HrTrainingType::class, $tenantId),
        ];
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $this->assertUnique('name', $data['name'] ?? null, $tenantId);
        $this->assertUnique('code', $data['code'] ?? null, $tenantId);

        $type = HrTrainingType::create([...$this->attrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
        $type->recordAudit('Training Type Created', $actor, null, ['name' => $type->name]);
        $this->log('Training type created', $tenantId, $type->id);

        return $this->present($type);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $type = $this->find($id, $tenantId);
        if (array_key_exists('name', $data)) {
            $this->assertUnique('name', $data['name'], $tenantId, $type->id);
        }
        if (array_key_exists('code', $data)) {
            $this->assertUnique('code', $data['code'], $tenantId, $type->id);
        }
        $type->update([...$this->attrs($data), 'updated_by' => $actor?->id]);
        $type->recordAudit('Training Type Updated', $actor, null, ['name' => $type->name]);

        return $this->present($type->fresh());
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $type = $this->find($id, $tenantId);
        $type->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $type->recordAudit($active ? 'Training Type Activated' : 'Training Type Deactivated', $actor);

        return $this->present($type->fresh());
    }

    private function assertUnique(string $field, ?string $value, int $tenantId, ?int $ignoreId = null): void
    {
        if (! trim((string) $value)) {
            throw new BusinessException(ucfirst($field).' is required.');
        }
        $exists = HrTrainingType::where('tenant_id', $tenantId)
            ->whereRaw("LOWER($field) = ?", [mb_strtolower(trim($value))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("A training type with this $field already exists.");
        }
    }

    private function attrs(array $d): array
    {
        $attrs = array_filter([
            'name'        => $d['name'] ?? null,
            'code'        => $d['code'] ?? null,
            'description' => $d['description'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('mode', $d)) {
            $mode = $d['mode'];
            $attrs['mode'] = in_array($mode, self::MODES, true) ? $mode : 'Offline';
        }
        if (array_key_exists('default_duration_hours', $d)) {
            $attrs['default_duration_hours'] = max(0, (int) $d['default_duration_hours']);
        }
        if (array_key_exists('certification_applicable', $d)) {
            $attrs['certification_applicable'] = (bool) $d['certification_applicable'];
        }
        if (array_key_exists('is_active', $d)) {
            $attrs['is_active'] = (bool) $d['is_active'];
        }

        return $attrs;
    }

    private function present(HrTrainingType $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'code' => $t->code,
            'mode' => $t->mode, 'default_duration_hours' => $t->default_duration_hours,
            'certification_applicable' => $t->certification_applicable,
            'description' => $t->description, 'is_active' => $t->is_active,
        ];
    }

    private function find(int $id, int $tenantId): HrTrainingType
    {
        $type = $this->repo->findType($id, $tenantId);
        if (! $type) {
            throw new BusinessException('Training type not found', 404);
        }

        return $type;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
