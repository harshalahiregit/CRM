<?php

namespace App\Services\Hr\Scoring\Dimensions;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;

/**
 * Candidate years vs the requisition's stated requirement.
 *
 * The old version used a fixed ladder (>=6 → 100, >=4 → 85 …) that ignored the job
 * entirely: six years scored 100 whether the role wanted two or twelve. Here the
 * requirement is the reference point, and with no stated requirement there is
 * nothing to compare against.
 */
class ExperienceDimension
{
    public const KEY = 'experience';

    public function score(HrCandidate $candidate, ?HrJobPosting $job): DimensionResult
    {
        $label = 'Experience';
        $years = $candidate->experience_years;

        if ($years === null || $years === '') {
            return DimensionResult::unavailable(self::KEY, $label, 'No experience recorded on the candidate.');
        }
        $years = (float) $years;

        $required = $this->requiredYears($job);
        if ($required === null) {
            return DimensionResult::unavailable(self::KEY, $label,
                'This role states no experience requirement to compare against.',
                ['candidate_years' => $years]);
        }

        if ($required <= 0) {
            return DimensionResult::scored(self::KEY, $label, 100,
                'This role has no minimum experience requirement.',
                ['candidate_years' => $years, 'required_years' => $required]);
        }

        $ratio = $years / $required;

        // At or above the bar is full marks. Being far OVER the bar is not penalised
        // here — over-qualification is a hiring judgement, not a fit deficit, and
        // encoding it as a lower score would hide a strong candidate.
        if ($ratio >= 1) {
            $score  = 100;
            $reason = sprintf('%s year(s) against a %s year requirement — meets the bar.',
                $this->fmt($years), $this->fmt($required));
        } else {
            // Linear shortfall: half the required experience scores 50.
            $score  = $ratio * 100;
            $reason = sprintf('%s year(s) against a %s year requirement — %d%% of the required experience.',
                $this->fmt($years), $this->fmt($required), (int) round($ratio * 100));
        }

        return DimensionResult::scored(self::KEY, $label, $score, $reason, [
            'candidate_years' => $years,
            'required_years'  => $required,
        ]);
    }

    /** Parse the requisition's free-text requirement ("3+ years", "5-7 yrs"). */
    private function requiredYears(?HrJobPosting $job): ?float
    {
        $raw = trim((string) ($job?->manpowerRequest?->experience_required ?? ''));
        if ($raw === '') {
            return null;
        }
        // Take the FIRST number — "5-7 years" and "5+ years" both mean a floor of 5.
        if (preg_match('/(\d+(?:\.\d+)?)/', $raw, $m)) {
            return (float) $m[1];
        }

        return null;   // unparseable text is missing data, not zero
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1), '0'), '.');
    }
}
