<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrExitType;
use App\Models\User;
use App\Repositories\Hr\ExitRepository;
use Illuminate\Support\Facades\Log;

/**
 * Exit Types master (Exit Phase 1). Tenant-unique name + code. Never hard-deleted —
 * deactivate to retire. Every mutation is audited.
 */
class ExitTypeService
{
    public function __construct(private ExitRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->types($tenantId, $f)->all(),
            'stats' => $this->repo->typeStats($tenantId),
        ];
    }

    public function create(array $data, int $tenantId, ?User $actor = null): HrExitType
    {
        $this->assertUnique('name', $data['name'] ?? null, $tenantId, null, 'An exit type');
        $this->assertUnique('code', $data['code'] ?? null, $tenantId, null, 'An exit type code');

        $type = HrExitType::create([...$this->attrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
        $type->recordAudit('Exit Type Created', $actor, null, ['code' => $type->code]);
        $this->log('Exit type created', $tenantId, $type->id);

        return $type;
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): HrExitType
    {
        $type = $this->find($id, $tenantId);
        if (array_key_exists('name', $data)) {
            $this->assertUnique('name', $data['name'], $tenantId, $type->id, 'An exit type');
        }
        if (array_key_exists('code', $data)) {
            $this->assertUnique('code', $data['code'], $tenantId, $type->id, 'An exit type code');
        }
        $type->update([...$this->attrs($data), 'updated_by' => $actor?->id]);
        $type->recordAudit('Exit Type Updated', $actor, null, ['code' => $type->code]);

        return $type->fresh();
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): HrExitType
    {
        $type = $this->find($id, $tenantId);
        $type->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $type->recordAudit($active ? 'Exit Type Activated' : 'Exit Type Deactivated', $actor);

        return $type->fresh();
    }

    /* ── Helpers ──────────────────────────────────────────── */
    private function attrs(array $d): array
    {
        $attrs = array_filter([
            'name'        => $d['name'] ?? null,
            'code'        => isset($d['code']) ? trim($d['code']) : null,
            'description' => $d['description'] ?? null,
        ], fn ($v) => $v !== null);

        foreach (['notice_required', 'clearance_required', 'fnf_required', 'exit_interview_required', 'is_active'] as $b) {
            if (array_key_exists($b, $d)) {
                $attrs[$b] = (bool) $d[$b];
            }
        }
        if (array_key_exists('default_notice_days', $d)) {
            $attrs['default_notice_days'] = (int) $d['default_notice_days'];
        }

        return $attrs;
    }

    private function assertUnique(string $column, ?string $value, int $tenantId, ?int $ignoreId, string $label): void
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new BusinessException(ucfirst($column).' is required.');
        }
        $exists = HrExitType::where('tenant_id', $tenantId)
            ->whereRaw("LOWER($column) = ?", [mb_strtolower($value)])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("{$label} “{$value}” already exists.");
        }
    }

    private function find(int $id, int $tenantId): HrExitType
    {
        $type = $this->repo->findType($id, $tenantId);
        if (! $type) {
            throw new BusinessException('Exit type not found', 404);
        }

        return $type;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
