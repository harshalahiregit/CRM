<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrTrainingCategory;
use App\Models\Hr\HrTrainingProgram;
use App\Models\Hr\HrTrainingProvider;
use App\Models\Hr\HrTrainingType;
use App\Models\User;
use App\Repositories\Hr\TrainingRepository;
use Illuminate\Support\Facades\Log;

/**
 * Training Programs (L&D Phase 2). Composes the Phase 1 masters (category / type /
 * provider) with an optional Organization Setup scope (department / designation) —
 * all validated against the tenant. Tenant-unique code + name. Never hard-deleted
 * (deactivate to retire). Tenant-scoped, audited.
 */
class TrainingProgramService
{
    private const DURATION_UNITS = ['Hours', 'Days', 'Weeks'];
    private const MODES = ['Online', 'Offline', 'Hybrid'];

    public function __construct(private TrainingRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->programs($tenantId, $f)->map(fn ($p) => $this->present($p))->all(),
            'stats' => $this->repo->programStats($tenantId),
        ];
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId));
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $this->assertUnique('program_code', $data['program_code'] ?? null, $tenantId);
        $this->assertUnique('program_name', $data['program_name'] ?? null, $tenantId);
        $this->assertRefs($data, $tenantId);

        $program = HrTrainingProgram::create([...$this->attrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
        $program->recordAudit('Training Program Created', $actor, null, ['name' => $program->program_name]);
        $this->log('Training program created', $tenantId, $program->id);

        return $this->show($program->id, $tenantId);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $program = $this->find($id, $tenantId);
        if (array_key_exists('program_code', $data)) {
            $this->assertUnique('program_code', $data['program_code'], $tenantId, $program->id);
        }
        if (array_key_exists('program_name', $data)) {
            $this->assertUnique('program_name', $data['program_name'], $tenantId, $program->id);
        }
        $this->assertRefs($data, $tenantId);

        $program->update([...$this->attrs($data), 'updated_by' => $actor?->id]);
        $program->recordAudit('Training Program Updated', $actor, null, ['name' => $program->program_name]);

        return $this->show($id, $tenantId);
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $program = $this->find($id, $tenantId);
        $program->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $program->recordAudit($active ? 'Training Program Activated' : 'Training Program Deactivated', $actor);

        return $this->show($id, $tenantId);
    }

    /* ── Validation ───────────────────────────────────────── */

    private function assertUnique(string $field, ?string $value, int $tenantId, ?int $ignoreId = null): void
    {
        $label = $field === 'program_code' ? 'code' : 'name';
        if (! trim((string) $value)) {
            throw new BusinessException('Program '.$label.' is required.');
        }
        $exists = HrTrainingProgram::where('tenant_id', $tenantId)
            ->whereRaw("LOWER($field) = ?", [mb_strtolower(trim($value))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("A training program with this $label already exists.");
        }
    }

    private function assertRefs(array $d, int $tenantId): void
    {
        // Masters are required on create (validated at controller); still guard tenant ownership.
        if (! empty($d['category_id']) && ! HrTrainingCategory::where('tenant_id', $tenantId)->where('id', $d['category_id'])->exists()) {
            throw new BusinessException('Selected training category is invalid.');
        }
        if (! empty($d['training_type_id']) && ! HrTrainingType::where('tenant_id', $tenantId)->where('id', $d['training_type_id'])->exists()) {
            throw new BusinessException('Selected training type is invalid.');
        }
        if (! empty($d['provider_id']) && ! HrTrainingProvider::where('tenant_id', $tenantId)->where('id', $d['provider_id'])->exists()) {
            throw new BusinessException('Selected training provider is invalid.');
        }
        if (! empty($d['department_id']) && ! HrDepartment::where('tenant_id', $tenantId)->where('id', $d['department_id'])->exists()) {
            throw new BusinessException('Selected department is invalid.');
        }
        if (! empty($d['designation_id']) && ! HrDesignation::where('tenant_id', $tenantId)->where('id', $d['designation_id'])->exists()) {
            throw new BusinessException('Selected designation is invalid.');
        }
        if (array_key_exists('duration', $d) && (int) $d['duration'] <= 0) {
            throw new BusinessException('Duration must be greater than zero.');
        }
        if (array_key_exists('capacity', $d) && (int) $d['capacity'] <= 0) {
            throw new BusinessException('Capacity must be greater than zero.');
        }
        if (array_key_exists('passing_percentage', $d) && $d['passing_percentage'] !== null && $d['passing_percentage'] !== '') {
            $pct = (int) $d['passing_percentage'];
            if ($pct < 0 || $pct > 100) {
                throw new BusinessException('Passing percentage must be between 0 and 100.');
            }
        }
    }

    private function attrs(array $d): array
    {
        $attrs = array_filter([
            'program_code' => $d['program_code'] ?? null,
            'program_name' => $d['program_name'] ?? null,
            'description'  => $d['description'] ?? null,
            'objectives'   => $d['objectives'] ?? null,
        ], fn ($v) => $v !== null);

        foreach (['category_id', 'training_type_id', 'provider_id'] as $ref) {
            if (array_key_exists($ref, $d)) {
                $attrs[$ref] = (int) $d[$ref];
            }
        }
        foreach (['department_id', 'designation_id'] as $ref) {
            if (array_key_exists($ref, $d)) {
                $attrs[$ref] = $d[$ref] ?: null;
            }
        }
        if (array_key_exists('duration', $d)) {
            $attrs['duration'] = max(1, (int) $d['duration']);
        }
        if (array_key_exists('duration_unit', $d)) {
            $attrs['duration_unit'] = in_array($d['duration_unit'], self::DURATION_UNITS, true) ? $d['duration_unit'] : 'Hours';
        }
        if (array_key_exists('mode', $d)) {
            $attrs['mode'] = in_array($d['mode'], self::MODES, true) ? $d['mode'] : 'Offline';
        }
        if (array_key_exists('capacity', $d)) {
            $attrs['capacity'] = max(1, (int) $d['capacity']);
        }
        if (array_key_exists('certification_applicable', $d)) {
            $attrs['certification_applicable'] = (bool) $d['certification_applicable'];
        }
        if (array_key_exists('passing_percentage', $d)) {
            $attrs['passing_percentage'] = max(0, min(100, (int) $d['passing_percentage']));
        }
        if (array_key_exists('validity_days', $d)) {
            $attrs['validity_days'] = ($d['validity_days'] === '' || $d['validity_days'] === null) ? null : max(0, (int) $d['validity_days']);
        }
        if (array_key_exists('is_active', $d)) {
            $attrs['is_active'] = (bool) $d['is_active'];
        }

        return $attrs;
    }

    private function present(HrTrainingProgram $p): array
    {
        return [
            'id' => $p->id,
            'program_code' => $p->program_code, 'program_name' => $p->program_name,
            'category_id' => $p->category_id, 'category' => $p->category?->name,
            'training_type_id' => $p->training_type_id, 'training_type' => $p->trainingType?->name, 'type_mode' => $p->trainingType?->mode,
            'provider_id' => $p->provider_id, 'provider' => $p->provider?->name, 'provider_type' => $p->provider?->provider_type,
            'department_id' => $p->department_id, 'department' => $p->department?->name,
            'designation_id' => $p->designation_id, 'designation' => $p->designation?->name,
            'description' => $p->description, 'objectives' => $p->objectives,
            'duration' => $p->duration, 'duration_unit' => $p->duration_unit, 'mode' => $p->mode,
            'capacity' => $p->capacity, 'certification_applicable' => $p->certification_applicable,
            'passing_percentage' => $p->passing_percentage, 'validity_days' => $p->validity_days,
            'is_active' => $p->is_active,
        ];
    }

    private function find(int $id, int $tenantId): HrTrainingProgram
    {
        $program = $this->repo->findProgram($id, $tenantId);
        if (! $program) {
            throw new BusinessException('Training program not found', 404);
        }

        return $program;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
