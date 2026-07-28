<?php

namespace App\Services\Hr\Scoring\Dimensions;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;

/**
 * Candidate's expected CTC against the role's budgeted band.
 *
 * Entirely new — the old engine never read `expected_ctc` despite it being captured
 * on 10 of 30 candidates.
 */
class SalaryDimension
{
    public const KEY = 'salary';

    public function score(HrCandidate $candidate, ?HrJobPosting $job): DimensionResult
    {
        $label = 'Salary Fit';

        $expected = $candidate->expected_ctc;
        if ($expected === null || (float) $expected <= 0) {
            return DimensionResult::unavailable(self::KEY, $label, 'No expected CTC recorded on the candidate.');
        }
        $expected = (float) $expected;

        [$min, $max] = $this->band($job);
        if ($max === null || $max <= 0) {
            return DimensionResult::unavailable(self::KEY, $label,
                'This role has no salary band to compare against.',
                ['expected_ctc' => $expected]);
        }
        $min = $min !== null && $min > 0 ? $min : null;

        if ($min !== null && $expected < $min) {
            // Below band: affordable, and not a fit problem. Full marks with a note —
            // an unusually low ask is for a human to interpret, not for the score to
            // silently penalise.
            return DimensionResult::scored(self::KEY, $label, 100, sprintf(
                'Expected %s is below the budgeted band (%s–%s).',
                $this->money($expected), $this->money($min), $this->money($max)
            ), ['expected_ctc' => $expected, 'salary_min' => $min, 'salary_max' => $max, 'position' => 'below_band']);
        }

        if ($expected <= $max) {
            return DimensionResult::scored(self::KEY, $label, 100, sprintf(
                'Expected %s sits within the budgeted band (%s–%s).',
                $this->money($expected), $this->money($min ?? 0), $this->money($max)
            ), ['expected_ctc' => $expected, 'salary_min' => $min, 'salary_max' => $max, 'position' => 'in_band']);
        }

        // Over band — score falls with the size of the overrun. 50% over budget
        // reaches 0; the gap itself is the measurement.
        $overrun = ($expected - $max) / $max;
        $score   = max(0, 100 - ($overrun / 0.5) * 100);

        return DimensionResult::scored(self::KEY, $label, $score, sprintf(
            'Expected %s exceeds the top of the band (%s) by %d%%.',
            $this->money($expected), $this->money($max), (int) round($overrun * 100)
        ), [
            'expected_ctc' => $expected, 'salary_min' => $min, 'salary_max' => $max,
            'overrun_pct'  => round($overrun * 100, 1), 'position' => 'above_band',
        ]);
    }

    /** @return array{0: ?float, 1: ?float} posting band first, requisition as fallback */
    private function band(?HrJobPosting $job): array
    {
        if (! $job) {
            return [null, null];
        }
        $min = $job->salary_from ?? $job->manpowerRequest?->salary_min;
        $max = $job->salary_to   ?? $job->manpowerRequest?->salary_max;

        return [$min !== null ? (float) $min : null, $max !== null ? (float) $max : null];
    }

    private function money(float $v): string
    {
        return '₹'.number_format($v, 0);
    }
}
