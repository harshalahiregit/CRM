<?php

namespace App\Services\Hr\Scoring\Employee\Dimensions;

use App\Models\Hr\HrEmployee;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;
use App\Support\Hr\SkillMatcher;

/**
 * #39 — the employee's skills against the ones their role expects.
 *
 * This is #43's comparison ("system indicate relevant skills and score of
 * individual to analyse") read as a score. SkillMatcher is REUSED rather than
 * re-implemented, so the gap shown on the org-setup screen and the gap scored
 * here can never disagree.
 */
class SkillFitDimension implements EmployeeDimension
{
    public const KEY = 'skill_fit';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Skill Fit';
    }

    public function score(HrEmployee $employee, array $ctx): DimensionResult
    {
        $expected = SkillMatcher::clean($ctx['expected_skills'] ?? []);
        $held     = SkillMatcher::clean($employee->skills ?? []);

        if ($expected === []) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'No skills are defined on this employee\'s department, designation, grade or role.');
        }

        if ($held === []) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'No skills are recorded on this employee, so there is nothing to compare.');
        }

        $comparison = SkillMatcher::compare($expected, $held);
        $matched    = $comparison['matched'] ?? [];
        $missing    = $comparison['missing'] ?? [];

        // SkillMatcher already computes the percentage — recomputing it here is
        // how the two screens would eventually disagree.
        $score = $comparison['score'];

        if ($score === null) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'No expected skills to compare against.');
        }

        return DimensionResult::scored(self::KEY, $this->label(), $score,
            sprintf('%d of %d expected skills held%s.',
                count($matched), count($expected),
                $missing ? '; missing '.implode(', ', array_slice($missing, 0, 4)) : ''),
            [
                'expected' => $expected,
                'matched'  => $matched,
                'missing'  => $missing,
            ]);
    }
}
