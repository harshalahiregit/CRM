<?php

namespace App\Services\Hr\Scoring\Dimensions;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;

/**
 * Candidate location vs job location, with remote work_mode short-circuiting the
 * comparison entirely.
 *
 * The old version defaulted to 60 whenever either side was missing. It now reports
 * unavailable instead.
 */
class LocationDimension
{
    public const KEY = 'location';

    public function score(HrCandidate $candidate, ?HrJobPosting $job): DimensionResult
    {
        $label = 'Location';

        if (! $job) {
            return DimensionResult::unavailable(self::KEY, $label, 'Candidate is not linked to a job posting.');
        }

        $mode = strtolower(trim((string) ($job->work_mode ?? $job->manpowerRequest?->work_mode ?? '')));
        $type = strtolower(trim((string) ($job->job_type ?? '')));

        // Remote roles make location irrelevant — full marks regardless of where the
        // candidate sits. This is a real measurement, not a fallback.
        if (str_contains($mode, 'remote') || str_contains($type, 'remote')) {
            return DimensionResult::scored(self::KEY, $label, 100,
                'This role is remote — location is not a constraint.',
                ['work_mode' => $mode ?: $type]);
        }

        $jobLoc  = trim((string) ($job->location ?? $job->manpowerRequest?->location ?? ''));
        $candLoc = trim((string) ($candidate->location ?? ''));

        if ($jobLoc === '') {
            return DimensionResult::unavailable(self::KEY, $label, 'This role states no work location.');
        }
        if ($candLoc === '') {
            return DimensionResult::unavailable(self::KEY, $label, 'No location recorded on the candidate.',
                ['job_location' => $jobLoc]);
        }

        $jobCity  = $this->city($jobLoc);
        $candCity = $this->city($candLoc);

        if ($jobCity !== '' && $jobCity === $candCity) {
            return DimensionResult::scored(self::KEY, $label, 100,
                sprintf('Candidate is in %s, the job location.', $jobLoc),
                ['job_location' => $jobLoc, 'candidate_location' => $candLoc]);
        }

        // Hybrid roles still require presence, but relocation is more plausible.
        $hybrid = str_contains($mode, 'hybrid');

        return DimensionResult::scored(self::KEY, $label, $hybrid ? 50 : 25, sprintf(
            'Candidate is in %s; the role is based in %s%s.',
            $candLoc, $jobLoc, $hybrid ? ' (hybrid)' : ''
        ), ['job_location' => $jobLoc, 'candidate_location' => $candLoc, 'work_mode' => $mode ?: null]);
    }

    /** First comma-separated component, lowercased — "Bangalore, KA" -> "bangalore". */
    private function city(string $loc): string
    {
        return strtolower(trim(explode(',', $loc)[0] ?? ''));
    }
}
