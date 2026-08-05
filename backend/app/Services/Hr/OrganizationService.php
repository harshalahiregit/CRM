<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrGrade;
use App\Models\Hr\HrJobRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Organization Setup — masters (Department / Designation / Grade / Role) plus the
 * reporting hierarchy. One cohesive service for four small, closely-related
 * masters: every write is tenant-scoped, audited, and logged; deletes are blocked
 * while a master is still referenced by an employee (or a designation, for grades).
 */
class OrganizationService
{
    /*
    |--------------------------------------------------------------------------
    | Departments
    |--------------------------------------------------------------------------
    */
    public function departments(int $tenantId): array
    {
        $counts = $this->employeeCountsBy('department_id', $tenantId);

        return HrDepartment::where('tenant_id', $tenantId)
            ->with('head:id,name,employee_code')
            ->orderBy('name')
            ->get()
            ->map(fn (HrDepartment $d) => [
                'id'             => $d->id,
                'name'           => $d->name,
                'code'           => $d->code,
                'head_employee_id' => $d->head_employee_id,
                'head_name'      => $d->head?->name,
                'description'    => $d->description,
                'is_active'      => $d->is_active,
                'skills'         => $d->skills ?: [],
                'employee_count' => $counts[$d->id] ?? 0,
            ])->all();
    }

    public function createDepartment(array $data, int $tenantId, ?User $actor = null): HrDepartment
    {
        $this->assertUniqueName(HrDepartment::class, $tenantId, $data['name']);

        $dept = HrDepartment::create([...$this->deptAttrs($data), 'tenant_id' => $tenantId]);
        $dept->recordAudit('Department Created', $actor, null, ['name' => $dept->name]);
        $this->log('Department created', $tenantId, $dept->id);

        return $dept;
    }

    public function updateDepartment(int $id, array $data, int $tenantId, ?User $actor = null): HrDepartment
    {
        $dept = $this->findTenant(HrDepartment::class, $id, $tenantId);
        if (array_key_exists('name', $data)) {
            $this->assertUniqueName(HrDepartment::class, $tenantId, $data['name'], $dept->id);
        }
        $dept->update($this->deptAttrs($data));
        $dept->recordAudit('Department Updated', $actor, null, ['name' => $dept->name]);

        return $dept->fresh('head');
    }

    public function deleteDepartment(int $id, int $tenantId, ?User $actor = null): void
    {
        $dept = $this->findTenant(HrDepartment::class, $id, $tenantId);
        $inUse = HrEmployee::where('tenant_id', $tenantId)->where('department_id', $dept->id)->count();
        if ($inUse > 0) {
            throw new BusinessException("Cannot delete “{$dept->name}” — {$inUse} employee(s) are still assigned to it.");
        }
        $dept->recordAudit('Department Deleted', $actor, null, ['name' => $dept->name]);
        $dept->delete();
        $this->log('Department deleted', $tenantId, $id);
    }

    private function deptAttrs(array $data): array
    {
        $attrs = array_filter([
            'name'             => $data['name'] ?? null,
            'code'             => $data['code'] ?? null,
            'head_employee_id' => $data['head_employee_id'] ?? null,
            'description'      => $data['description'] ?? null,
            'is_active'        => $data['is_active'] ?? null,
        ], fn ($v) => $v !== null);

        // #43 — skills are assigned explicitly: array_filter() above would drop an
        // intentional empty list, making it impossible to clear a skill profile.
        if (array_key_exists('skills', $data)) {
            $attrs['skills'] = \App\Support\Hr\SkillMatcher::clean($data['skills'] ?? []);
        }

        return $attrs;
    }

    /*
    |--------------------------------------------------------------------------
    | Designations
    |--------------------------------------------------------------------------
    */
    public function designations(int $tenantId): array
    {
        $counts = $this->employeeCountsBy('designation_id', $tenantId);

        return HrDesignation::where('tenant_id', $tenantId)
            ->with('grade:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (HrDesignation $d) => [
                'id'             => $d->id,
                'name'           => $d->name,
                'code'           => $d->code,
                'grade_id'       => $d->grade_id,
                'grade_name'     => $d->grade?->name,
                'description'    => $d->description,
                'is_active'      => $d->is_active,
                'skills'         => $d->skills ?: [],
                'employee_count' => $counts[$d->id] ?? 0,
            ])->all();
    }

    public function createDesignation(array $data, int $tenantId, ?User $actor = null): HrDesignation
    {
        $this->assertUniqueName(HrDesignation::class, $tenantId, $data['name']);
        $this->assertGradeBelongs($data['grade_id'] ?? null, $tenantId);

        $des = HrDesignation::create([...$this->desigAttrs($data), 'tenant_id' => $tenantId]);
        $des->recordAudit('Designation Created', $actor, null, ['name' => $des->name]);
        $this->log('Designation created', $tenantId, $des->id);

        return $des;
    }

    public function updateDesignation(int $id, array $data, int $tenantId, ?User $actor = null): HrDesignation
    {
        $des = $this->findTenant(HrDesignation::class, $id, $tenantId);
        if (array_key_exists('name', $data)) {
            $this->assertUniqueName(HrDesignation::class, $tenantId, $data['name'], $des->id);
        }
        $this->assertGradeBelongs($data['grade_id'] ?? null, $tenantId);
        $des->update($this->desigAttrs($data));
        $des->recordAudit('Designation Updated', $actor, null, ['name' => $des->name]);

        return $des->fresh('grade');
    }

    public function deleteDesignation(int $id, int $tenantId, ?User $actor = null): void
    {
        $des = $this->findTenant(HrDesignation::class, $id, $tenantId);
        $inUse = HrEmployee::where('tenant_id', $tenantId)->where('designation_id', $des->id)->count();
        if ($inUse > 0) {
            throw new BusinessException("Cannot delete “{$des->name}” — {$inUse} employee(s) still hold this designation.");
        }
        $des->recordAudit('Designation Deleted', $actor, null, ['name' => $des->name]);
        $des->delete();
        $this->log('Designation deleted', $tenantId, $id);
    }

    private function desigAttrs(array $data): array
    {
        // grade_id is nullable-clearable, so it is handled explicitly (array_filter would drop a null).
        $attrs = array_filter([
            'name'        => $data['name'] ?? null,
            'code'        => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active'   => $data['is_active'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('grade_id', $data)) {
            $attrs['grade_id'] = $data['grade_id'] ?: null;
        }

        // #43 — skills are assigned explicitly: array_filter() above would drop an
        // intentional empty list, making it impossible to clear a skill profile.
        if (array_key_exists('skills', $data)) {
            $attrs['skills'] = \App\Support\Hr\SkillMatcher::clean($data['skills'] ?? []);
        }

        return $attrs;
    }

    /*
    |--------------------------------------------------------------------------
    | Grades
    |--------------------------------------------------------------------------
    */
    public function grades(int $tenantId): array
    {
        return HrGrade::where('tenant_id', $tenantId)
            ->withCount('designations')
            ->orderByRaw('COALESCE(level, 999999)')
            ->orderBy('name')
            ->get()
            ->map(fn (HrGrade $g) => [
                'id'                => $g->id,
                'name'              => $g->name,
                'code'              => $g->code,
                'level'             => $g->level,
                'description'       => $g->description,
                'is_active'         => $g->is_active,
                'skills'            => $g->skills ?: [],
                'designation_count' => $g->designations_count,
            ])->all();
    }

    public function createGrade(array $data, int $tenantId, ?User $actor = null): HrGrade
    {
        $this->assertUniqueName(HrGrade::class, $tenantId, $data['name']);

        $grade = HrGrade::create([...$this->gradeAttrs($data), 'tenant_id' => $tenantId]);
        $grade->recordAudit('Grade Created', $actor, null, ['name' => $grade->name]);
        $this->log('Grade created', $tenantId, $grade->id);

        return $grade;
    }

    public function updateGrade(int $id, array $data, int $tenantId, ?User $actor = null): HrGrade
    {
        $grade = $this->findTenant(HrGrade::class, $id, $tenantId);
        if (array_key_exists('name', $data)) {
            $this->assertUniqueName(HrGrade::class, $tenantId, $data['name'], $grade->id);
        }
        $grade->update($this->gradeAttrs($data));
        $grade->recordAudit('Grade Updated', $actor, null, ['name' => $grade->name]);

        return $grade->fresh();
    }

    public function deleteGrade(int $id, int $tenantId, ?User $actor = null): void
    {
        $grade = $this->findTenant(HrGrade::class, $id, $tenantId);
        $inUse = HrDesignation::where('tenant_id', $tenantId)->where('grade_id', $grade->id)->count();
        if ($inUse > 0) {
            throw new BusinessException("Cannot delete grade “{$grade->name}” — {$inUse} designation(s) reference it.");
        }
        $grade->recordAudit('Grade Deleted', $actor, null, ['name' => $grade->name]);
        $grade->delete();
        $this->log('Grade deleted', $tenantId, $id);
    }

    private function gradeAttrs(array $data): array
    {
        $attrs = array_filter([
            'name'        => $data['name'] ?? null,
            'code'        => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active'   => $data['is_active'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('level', $data)) {
            $attrs['level'] = $data['level'] === '' ? null : $data['level'];
        }

        // #43 — skills are assigned explicitly: array_filter() above would drop an
        // intentional empty list, making it impossible to clear a skill profile.
        if (array_key_exists('skills', $data)) {
            $attrs['skills'] = \App\Support\Hr\SkillMatcher::clean($data['skills'] ?? []);
        }

        return $attrs;
    }

    /*
    |--------------------------------------------------------------------------
    | Job Roles
    |--------------------------------------------------------------------------
    */
    public function roles(int $tenantId): array
    {
        $counts = $this->employeeCountsBy('job_role_id', $tenantId);

        return HrJobRole::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get()
            ->map(fn (HrJobRole $r) => [
                'id'             => $r->id,
                'name'           => $r->name,
                'code'           => $r->code,
                'description'    => $r->description,
                'is_active'      => $r->is_active,
                'skills'         => $r->skills ?: [],
                'employee_count' => $counts[$r->id] ?? 0,
            ])->all();
    }

    public function createRole(array $data, int $tenantId, ?User $actor = null): HrJobRole
    {
        $this->assertUniqueName(HrJobRole::class, $tenantId, $data['name']);

        $role = HrJobRole::create([...$this->roleAttrs($data), 'tenant_id' => $tenantId]);
        $role->recordAudit('Role Created', $actor, null, ['name' => $role->name]);
        $this->log('Role created', $tenantId, $role->id);

        return $role;
    }

    public function updateRole(int $id, array $data, int $tenantId, ?User $actor = null): HrJobRole
    {
        $role = $this->findTenant(HrJobRole::class, $id, $tenantId);
        if (array_key_exists('name', $data)) {
            $this->assertUniqueName(HrJobRole::class, $tenantId, $data['name'], $role->id);
        }
        $role->update($this->roleAttrs($data));
        $role->recordAudit('Role Updated', $actor, null, ['name' => $role->name]);

        return $role->fresh();
    }

    public function deleteRole(int $id, int $tenantId, ?User $actor = null): void
    {
        $role = $this->findTenant(HrJobRole::class, $id, $tenantId);
        $inUse = HrEmployee::where('tenant_id', $tenantId)->where('job_role_id', $role->id)->count();
        if ($inUse > 0) {
            throw new BusinessException("Cannot delete role “{$role->name}” — {$inUse} employee(s) are assigned to it.");
        }
        $role->recordAudit('Role Deleted', $actor, null, ['name' => $role->name]);
        $role->delete();
        $this->log('Role deleted', $tenantId, $id);
    }

    private function roleAttrs(array $data): array
    {
        $attrs = array_filter([
            'name'        => $data['name'] ?? null,
            'code'        => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active'   => $data['is_active'] ?? null,
        ], fn ($v) => $v !== null);

        // #43 — skills are assigned explicitly: array_filter() above would drop an
        // intentional empty list, making it impossible to clear a skill profile.
        if (array_key_exists('skills', $data)) {
            $attrs['skills'] = \App\Support\Hr\SkillMatcher::clean($data['skills'] ?? []);
        }

        return $attrs;
    }

    /*
    |--------------------------------------------------------------------------
    | Overview / Options / Hierarchy
    |--------------------------------------------------------------------------
    */
    public function overview(int $tenantId): array
    {
        return [
            'departments' => HrDepartment::where('tenant_id', $tenantId)->count(),
            'designations'=> HrDesignation::where('tenant_id', $tenantId)->count(),
            'grades'      => HrGrade::where('tenant_id', $tenantId)->count(),
            'roles'       => HrJobRole::where('tenant_id', $tenantId)->count(),
            'employees'   => HrEmployee::where('tenant_id', $tenantId)->count(),
            'unassigned'  => HrEmployee::where('tenant_id', $tenantId)->whereNull('department_id')->count(),
        ];
    }

    /** Enum masters that have no dedicated table — defined ONCE here (single source). */
    public const SHIFTS = ['Day', 'Night', 'Rotational', 'Flexible'];

    public const EMPLOYEE_LEVELS = ['Intern', 'Junior', 'Mid-level', 'Senior', 'Lead', 'Manager', 'Director'];

    /**
     * ONE shared master-data payload for EVERY Recruitment dropdown (GET /hr/master-data).
     * Reuses the exact active-only masters as options() — Departments / Designations /
     * Grades / Roles / Employees — and adds the enum + derived sets. Single source of
     * truth: nothing is hardcoded per Recruitment page.
     */
    public function masterData(int $tenantId): array
    {
        $base = $this->options($tenantId);

        return [
            'departments'     => $base['departments'],
            'designations'    => $base['designations'],
            'grades'          => $base['grades'],
            'roles'           => $base['roles'],
            // Employees are the pool for Hiring Managers, Reporting Managers and Interviewers.
            'managers'        => $base['employees'],
            // No master table for these — derived from real tenant data, deduped.
            'business_units'  => $this->distinctValues(\App\Models\Hr\HrManpowerRequest::class, 'business_unit', $tenantId),
            'locations'       => $this->distinctLocations($tenantId),
            // Projects reuse the Project module's OWN service (single source of truth,
            // active-only). No duplicate project store; included here so every HR
            // dropdown reads it from the one cached master payload.
            'projects'        => app(\App\Services\Project\ProjectService::class)->options($tenantId),
            // Fixed enums with no table — defined once above.
            'shifts'          => self::SHIFTS,
            'employee_levels' => self::EMPLOYEE_LEVELS,
        ];
    }

    /** Distinct non-empty values of a column for a tenant (used for derived masters). */
    private function distinctValues(string $modelClass, string $column, int $tenantId): array
    {
        return $modelClass::where('tenant_id', $tenantId)
            ->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column)->values()->all();
    }

    /** Locations aggregated across the recruitment chain (MR + Job Posting + Employee). */
    private function distinctLocations(int $tenantId): array
    {
        $sources = [
            [\App\Models\Hr\HrManpowerRequest::class, 'location'],
            [\App\Models\Hr\HrJobPosting::class, 'location'],
            [HrEmployee::class, 'location'],
        ];
        $values = collect();
        foreach ($sources as [$model, $column]) {
            $values = $values->merge(
                $model::where('tenant_id', $tenantId)->whereNotNull($column)->where($column, '!=', '')->distinct()->pluck($column)
            );
        }

        return $values->filter()->unique()->sort()->values()->all();
    }

    /** Flat master lists for dropdowns elsewhere (employee forms, letters, PMS…). */
    public function options(int $tenantId): array
    {
        $slim = fn ($q) => $q->where('tenant_id', $tenantId)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name'])->all();

        return [
            'departments'  => $slim(HrDepartment::query()),
            'designations' => $slim(HrDesignation::query()),
            'grades'       => $slim(HrGrade::query()),
            'roles'        => $slim(HrJobRole::query()),
            'employees'    => HrEmployee::where('tenant_id', $tenantId)->orderBy('name')
                ->get(['id', 'name', 'employee_code'])->all(),
        ];
    }

    /**
     * Reporting hierarchy, department-centric: each department, its head, and the
     * employees within it (with their textual reporting manager). Honest to the
     * data we have today — reporting_manager_id is optional, so the manager name
     * is surfaced as-is where no id link exists yet.
     */
    public function hierarchy(int $tenantId): array
    {
        $departments = HrDepartment::where('tenant_id', $tenantId)
            ->with('head:id,name,employee_code')
            ->orderBy('name')
            ->get();

        $employees = HrEmployee::where('tenant_id', $tenantId)
            ->get(['id', 'name', 'employee_code', 'designation', 'department_id', 'reporting_manager_name', 'status']);

        $mapEmp = fn ($e) => [
            'id'                     => $e->id,
            'name'                   => $e->name,
            'employee_code'          => $e->employee_code,
            'designation'            => $e->designation,
            'reporting_manager_name' => $e->reporting_manager_name,
            'status'                 => $e->status,
        ];

        $nodes = $departments->map(fn (HrDepartment $d) => [
            'id'        => $d->id,
            'name'      => $d->name,
            'head'      => $d->head ? ['id' => $d->head->id, 'name' => $d->head->name, 'employee_code' => $d->head->employee_code] : null,
            'members'   => $employees->where('department_id', $d->id)->map($mapEmp)->values()->all(),
        ])->all();

        return [
            'departments' => $nodes,
            'unassigned'  => $employees->whereNull('department_id')->map($mapEmp)->values()->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */
    private function employeeCountsBy(string $column, int $tenantId): array
    {
        return HrEmployee::where('tenant_id', $tenantId)
            ->whereNotNull($column)
            ->selectRaw("$column as ref_id, count(*) as c")
            ->groupBy($column)
            ->pluck('c', 'ref_id')
            ->all();
    }

    private function findTenant(string $modelClass, int $id, int $tenantId): Model
    {
        $model = $modelClass::where('tenant_id', $tenantId)->find($id);
        if (! $model) {
            throw new BusinessException(class_basename($modelClass).' not found', 404);
        }

        return $model;
    }

    private function assertUniqueName(string $modelClass, int $tenantId, ?string $name, ?int $ignoreId = null): void
    {
        $name = trim((string) $name);
        $exists = $modelClass::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw new BusinessException("“{$name}” already exists.");
        }
    }

    private function assertGradeBelongs(?int $gradeId, int $tenantId): void
    {
        if ($gradeId && ! HrGrade::where('tenant_id', $tenantId)->where('id', $gradeId)->exists()) {
            throw new BusinessException('Selected grade is invalid.');
        }
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
