<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrGrade;
use App\Models\Hr\HrJobRole;
use App\Models\User;
use App\Support\Hr\SkillMatcher;

/**
 * Review comment #43 — "when we assign any emp to these (dept/designation etc.),
 * system indicate relevant skills and score of individual to analyse".
 *
 * Answers one question: given where this employee sits, which skills does the org
 * expect of them, which do they have, and what is missing?
 *
 * Each of the four masters is scored SEPARATELY as well as combined. A person can
 * be a perfect fit for their designation and a poor fit for their department's
 * broader expectations — collapsing that into one number would hide the finding
 * the comment is asking for.
 *
 * Positions with no skills configured score `null`, not 0. Nobody has said what is
 * expected, so there is nothing to fall short of.
 */
class EmployeeSkillService
{
    /** Analyse an employee against every master they are attached to. */
    public function analyse(HrEmployee $employee, int $tenantId): array
    {
        $held = SkillMatcher::clean($employee->skills);
        $sources = [];

        foreach ($this->positionSkills($employee, $tenantId) as $source) {
            $comparison = SkillMatcher::compare($source['skills'], $held);
            $sources[] = [
                'type'    => $source['type'],
                'name'    => $source['name'],
                'expected' => $source['skills'],
                'score'   => $comparison['score'],
                'matched' => $comparison['matched'],
                'missing' => $comparison['missing'],
            ];
        }

        // The combined view compares against the UNION of every expectation, so a
        // skill required by both department and designation is not counted twice.
        $allExpected = SkillMatcher::clean(array_merge(...array_map(fn ($s) => $s['expected'], $sources) ?: [[]]));
        $overall = SkillMatcher::compare($allExpected, $held);

        return [
            'employee_id'    => $employee->id,
            'employee_skills' => $held,
            'sources'        => $sources,
            'overall'        => [
                'score'          => $overall['score'],
                'expected_count' => $overall['expected_count'],
                'matched'        => $overall['matched'],
                'missing'        => $overall['missing'],
                'extra'          => $overall['extra'],
            ],
            'configured' => $allExpected !== [],
        ];
    }

    /**
     * Preview the fit BEFORE assigning — the "system indicate … to analyse" half
     * of the comment. Takes the target masters rather than the employee's current
     * ones, so a transfer can be sanity-checked before it happens.
     */
    public function preview(int $employeeId, int $tenantId, array $target): array
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        $held = SkillMatcher::clean($employee->skills);
        $sources = [];

        $lookups = [
            'department'  => [HrDepartment::class, $target['department_id'] ?? null],
            'designation' => [HrDesignation::class, $target['designation_id'] ?? null],
            'grade'       => [HrGrade::class, $target['grade_id'] ?? null],
            'job_role'    => [HrJobRole::class, $target['job_role_id'] ?? null],
        ];

        foreach ($lookups as $type => [$class, $id]) {
            if (! $id) {
                continue;
            }
            $row = $class::where('tenant_id', $tenantId)->find((int) $id);
            if (! $row) {
                continue;
            }
            $comparison = SkillMatcher::compare($row->skills, $held);
            $sources[] = [
                'type' => $type, 'name' => $row->name, 'expected' => SkillMatcher::clean($row->skills),
                'score' => $comparison['score'], 'matched' => $comparison['matched'], 'missing' => $comparison['missing'],
            ];
        }

        $allExpected = SkillMatcher::clean(array_merge(...array_map(fn ($s) => $s['expected'], $sources) ?: [[]]));
        $overall = SkillMatcher::compare($allExpected, $held);

        return [
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'employee_skills' => $held,
            'sources' => $sources,
            'overall' => [
                'score' => $overall['score'], 'expected_count' => $overall['expected_count'],
                'matched' => $overall['matched'], 'missing' => $overall['missing'], 'extra' => $overall['extra'],
            ],
            'configured' => $allExpected !== [],
        ];
    }

    /** Replace an employee's own skill list. */
    public function setSkills(int $employeeId, array $skills, int $tenantId, ?User $actor = null): array
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        $employee->update(['skills' => SkillMatcher::clean($skills)]);
        $employee->recordAudit('Employee Skills Updated', $actor, null, ['count' => count($employee->skills ?? [])]);

        return $this->analyse($employee->fresh(), $tenantId);
    }

    /**
     * Every skill expectation attached to this employee's current position.
     *
     * Masters are resolved by ID where the employee has one, falling back to a
     * name lookup — `hr_employees` stores department/designation as free text and
     * the `*_id` columns are only populated for records created since they were
     * added, so an ID-only lookup would silently find nothing for older rows.
     */
    private function positionSkills(HrEmployee $employee, int $tenantId): array
    {
        $out = [];

        $department = $employee->department_id
            ? HrDepartment::where('tenant_id', $tenantId)->find($employee->department_id)
            : ($employee->department ? HrDepartment::where('tenant_id', $tenantId)->where('name', $employee->department)->first() : null);

        $designation = $employee->designation_id
            ? HrDesignation::where('tenant_id', $tenantId)->find($employee->designation_id)
            : ($employee->designation ? HrDesignation::where('tenant_id', $tenantId)->where('name', $employee->designation)->first() : null);

        $grade    = $employee->grade_id ? HrGrade::where('tenant_id', $tenantId)->find($employee->grade_id) : null;
        $jobRole  = $employee->job_role_id ? HrJobRole::where('tenant_id', $tenantId)->find($employee->job_role_id) : null;

        foreach ([['department', $department], ['designation', $designation], ['grade', $grade], ['job_role', $jobRole]] as [$type, $row]) {
            if ($row && SkillMatcher::clean($row->skills) !== []) {
                $out[] = ['type' => $type, 'name' => $row->name, 'skills' => SkillMatcher::clean($row->skills)];
            }
        }

        return $out;
    }
}
