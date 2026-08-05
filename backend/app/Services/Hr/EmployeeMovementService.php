<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeMovement;
use App\Models\Hr\HrGrade;
use App\Models\Hr\HrPromotionRecommendation;
use App\Models\User;
use App\Support\Hr\SkillMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Review comments #41 and #42 — moving an employee between departments and
 * between positions.
 *
 * What existed before was HrPromotionRecommendation: a review OUTCOME that named
 * a recommended designation and went no further. Nothing ever moved the employee.
 * This service is the missing action, and it can consume a recommendation
 * (marking it Actioned) or run standalone for a plain transfer.
 *
 * Every move writes an immutable HrEmployeeMovement row AND updates the employee
 * in the same transaction. Doing one without the other would leave the employee's
 * current position and their history disagreeing.
 *
 * Promotion vs demotion is decided by GRADE LEVEL where grades are set, because
 * that is the only ordered thing in the org masters — designations have no rank.
 * Where grade cannot decide, the caller's stated type is trusted rather than
 * guessed at from job titles.
 */
class EmployeeMovementService
{
    public function __construct(private EmployeeSkillService $skills)
    {
    }

    /* ── Read ─────────────────────────────────────────────────────────── */

    public function history(int $employeeId, int $tenantId): array
    {
        return HrEmployeeMovement::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->orderByDesc('effective_date')->orderByDesc('id')
            ->get()->map(fn ($m) => $this->present($m))->all();
    }

    public function list(int $tenantId, array $filters = []): array
    {
        $q = HrEmployeeMovement::forTenant($tenantId)->with('employee:id,name,employee_code');

        if (! empty($filters['movement_type'])) {
            $q->where('movement_type', $filters['movement_type']);
        }
        if (! empty($filters['employee_id'])) {
            $q->where('employee_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['from'])) {
            $q->whereDate('effective_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('effective_date', '<=', $filters['to']);
        }

        return $q->orderByDesc('effective_date')->orderByDesc('id')
            ->get()->map(fn ($m) => $this->present($m, withEmployee: true))->all();
    }

    /* ── Write ────────────────────────────────────────────────────────── */

    /**
     * Move an employee. Handles transfer, promotion, demotion and redesignation.
     *
     * At least one of department / designation / grade must actually change —
     * recording a movement where nothing moved would pollute the history with
     * rows that explain nothing.
     */
    public function move(array $data, int $tenantId, ?User $actor = null): array
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find((int) ($data['employee_id'] ?? 0));
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        $toDepartment  = $this->resolveDepartment($data, $tenantId);
        $toDesignation = $this->resolveDesignation($data, $tenantId);
        $toGrade       = $this->resolveGrade($data, $tenantId);

        $changed = ($toDepartment && $toDepartment['name'] !== $employee->department)
            || ($toDesignation && $toDesignation['name'] !== $employee->designation)
            || ($toGrade && (int) $toGrade['id'] !== (int) $employee->grade_id)
            || (array_key_exists('to_manager_id', $data) && (int) $data['to_manager_id'] !== (int) $employee->reporting_manager_id);

        if (! $changed) {
            throw new BusinessException('Nothing would change — choose a different department, designation, grade or manager.');
        }

        $type = $this->resolveType($data, $employee, $toGrade, $toDepartment);
        $effective = Carbon::parse($data['effective_date'] ?? now())->startOfDay();

        $movement = DB::transaction(function () use ($employee, $tenantId, $type, $effective, $toDepartment, $toDesignation, $toGrade, $data, $actor) {
            $movement = HrEmployeeMovement::create([
                'tenant_id'     => $tenantId,
                'employee_id'   => $employee->id,
                'movement_type' => $type,
                'effective_date' => $effective->toDateString(),

                'from_department_id'  => $employee->department_id,
                'from_department'     => $employee->department,
                'to_department_id'    => $toDepartment['id'] ?? $employee->department_id,
                'to_department'       => $toDepartment['name'] ?? $employee->department,

                'from_designation_id' => $employee->designation_id,
                'from_designation'    => $employee->designation,
                'to_designation_id'   => $toDesignation['id'] ?? $employee->designation_id,
                'to_designation'      => $toDesignation['name'] ?? $employee->designation,

                'from_grade_id'   => $employee->grade_id,
                'to_grade_id'     => $toGrade['id'] ?? $employee->grade_id,
                'from_manager_id' => $employee->reporting_manager_id,
                'to_manager_id'   => array_key_exists('to_manager_id', $data)
                    ? ($data['to_manager_id'] ?: null) : $employee->reporting_manager_id,

                'promotion_recommendation_id' => $data['promotion_recommendation_id'] ?? null,
                'reason'      => $data['reason'] ?? null,
                'remarks'     => $data['remarks'] ?? null,
                'actioned_by' => $actor?->id,
            ]);

            // Apply to the employee in the SAME transaction — a movement recorded
            // without the employee moving is a lie in the history.
            $employee->update(array_filter([
                'department'    => $toDepartment['name'] ?? null,
                'department_id' => $toDepartment['id'] ?? null,
                'designation'   => $toDesignation['name'] ?? null,
                'designation_id' => $toDesignation['id'] ?? null,
                'grade_id'      => $toGrade['id'] ?? null,
            ], fn ($v) => $v !== null) + (array_key_exists('to_manager_id', $data)
                ? ['reporting_manager_id' => $data['to_manager_id'] ?: null] : []));

            // A movement raised from a recommendation closes it, so the same
            // recommendation cannot be actioned twice.
            if (! empty($data['promotion_recommendation_id'])) {
                HrPromotionRecommendation::where('tenant_id', $tenantId)
                    ->where('id', $data['promotion_recommendation_id'])
                    ->update(['status' => 'Actioned']);
            }

            return $movement;
        });

        $movement->recordAudit("Employee {$type}", $actor, $data['reason'] ?? null, [
            'employee' => $employee->name, 'summary' => $movement->summary(),
        ]);
        Log::channel('hr')->info('Employee movement recorded', [
            'tenant_id' => $tenantId, 'employee_id' => $employee->id, 'type' => $type,
        ]);

        return $this->present($movement->fresh()) + [
            // #43 — the new position's skill expectations against what they have,
            // returned with the move so the gap is visible at the moment it is created.
            'skill_analysis' => $this->skills->analyse($employee->fresh(), $tenantId),
        ];
    }

    /**
     * Action a promotion recommendation directly.
     *
     * The recommendation already names the target designation; this turns it into
     * a movement without re-typing it.
     */
    public function actionRecommendation(int $recommendationId, array $data, int $tenantId, ?User $actor = null): array
    {
        $rec = HrPromotionRecommendation::where('tenant_id', $tenantId)->find($recommendationId);
        if (! $rec) {
            throw new BusinessException('Promotion recommendation not found', 404);
        }
        if ($rec->status === 'Actioned') {
            throw new BusinessException('This recommendation has already been actioned.');
        }
        if (! $rec->recommended_designation) {
            throw new BusinessException('This recommendation names no target designation.');
        }

        return $this->move([
            'employee_id'    => $rec->employee_id,
            'to_designation' => $rec->recommended_designation,
            'movement_type'  => $data['movement_type'] ?? HrEmployeeMovement::PROMOTION,
            'effective_date' => $data['effective_date'] ?? now()->toDateString(),
            'reason'         => $data['reason'] ?? $rec->reason,
            'promotion_recommendation_id' => $rec->id,
        ], $tenantId, $actor);
    }

    /* ── Resolution helpers ───────────────────────────────────────────── */

    /**
     * Accepts either an id or a name for each master, because the employee record
     * stores names and the UI sends ids. Returns null when the caller did not ask
     * to change that dimension at all.
     */
    private function resolveDepartment(array $data, int $tenantId): ?array
    {
        if (! empty($data['to_department_id'])) {
            $row = HrDepartment::where('tenant_id', $tenantId)->find((int) $data['to_department_id']);
            if (! $row) {
                throw new BusinessException('Department not found', 404);
            }

            return ['id' => $row->id, 'name' => $row->name];
        }
        if (! empty($data['to_department'])) {
            $row = HrDepartment::where('tenant_id', $tenantId)->where('name', $data['to_department'])->first();

            return ['id' => $row?->id, 'name' => $data['to_department']];
        }

        return null;
    }

    private function resolveDesignation(array $data, int $tenantId): ?array
    {
        if (! empty($data['to_designation_id'])) {
            $row = HrDesignation::where('tenant_id', $tenantId)->find((int) $data['to_designation_id']);
            if (! $row) {
                throw new BusinessException('Designation not found', 404);
            }

            return ['id' => $row->id, 'name' => $row->name];
        }
        if (! empty($data['to_designation'])) {
            $row = HrDesignation::where('tenant_id', $tenantId)->where('name', $data['to_designation'])->first();

            return ['id' => $row?->id, 'name' => $data['to_designation']];
        }

        return null;
    }

    private function resolveGrade(array $data, int $tenantId): ?array
    {
        if (empty($data['to_grade_id'])) {
            return null;
        }
        $row = HrGrade::where('tenant_id', $tenantId)->find((int) $data['to_grade_id']);
        if (! $row) {
            throw new BusinessException('Grade not found', 404);
        }

        return ['id' => $row->id, 'level' => $row->level];
    }

    /**
     * Which kind of move this is.
     *
     * An explicit type from the caller always wins. Otherwise: grade level decides
     * promotion vs demotion (it is the only RANKED master — designations carry no
     * order, so inferring seniority from a job title would be guesswork); a
     * department change with no position change is a Transfer; anything else is a
     * Redesignation.
     */
    private function resolveType(array $data, HrEmployee $employee, ?array $toGrade, ?array $toDepartment): string
    {
        $stated = $data['movement_type'] ?? null;
        if ($stated && in_array($stated, HrEmployeeMovement::TYPES, true)) {
            return $stated;
        }

        if ($toGrade && $toGrade['level'] !== null && $employee->grade_id) {
            $current = HrGrade::find($employee->grade_id);
            if ($current && $current->level !== null) {
                if ((int) $toGrade['level'] > (int) $current->level) {
                    return HrEmployeeMovement::PROMOTION;
                }
                if ((int) $toGrade['level'] < (int) $current->level) {
                    return HrEmployeeMovement::DEMOTION;
                }
            }
        }

        if ($toDepartment && $toDepartment['name'] !== $employee->department) {
            return HrEmployeeMovement::TRANSFER;
        }

        return HrEmployeeMovement::REDESIGNATION;
    }

    private function present(HrEmployeeMovement $m, bool $withEmployee = false): array
    {
        $out = [
            'id'             => $m->id,
            'employee_id'    => $m->employee_id,
            'movement_type'  => $m->movement_type,
            'effective_date' => optional($m->effective_date)->toDateString(),
            'from_department' => $m->from_department,
            'to_department'   => $m->to_department,
            'from_designation' => $m->from_designation,
            'to_designation'   => $m->to_designation,
            'from_grade_id'   => $m->from_grade_id,
            'to_grade_id'     => $m->to_grade_id,
            'summary'         => $m->summary(),
            'reason'          => $m->reason,
            'remarks'         => $m->remarks,
            'from_recommendation' => $m->promotion_recommendation_id !== null,
            'created_at'      => optional($m->created_at)->toIso8601String(),
        ];

        if ($withEmployee) {
            $out['employee_name'] = $m->employee?->name;
            $out['employee_code'] = $m->employee?->employee_code;
        }

        return $out;
    }
}
