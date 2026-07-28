<?php

namespace App\Services\Hr\Scoring\Dimensions;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Services\Hr\Scoring\SkillMatcher;

/**
 * Candidate education vs the requisition's education requirement.
 *
 * The old engine hardcoded 70 here and never read the candidate at all — while a
 * working scoreEducation() sat unused two methods away, and its result was excluded
 * from the weighted sum. Both defects are removed: this reads real data or reports
 * that it cannot.
 *
 * Note `hr_candidates.education` is unpopulated across the current dataset, so this
 * dimension will legitimately be unavailable for most candidates until HR captures
 * it. That is the intended behaviour, not a regression.
 */
class EducationDimension
{
    public const KEY = 'education';

    public function score(HrCandidate $candidate, ?HrJobPosting $job): DimensionResult
    {
        $label = 'Education';

        $required = trim((string) ($job?->manpowerRequest?->education ?? ''));
        $have     = $this->candidateEducation($candidate);

        if ($required === '') {
            return DimensionResult::unavailable(self::KEY, $label,
                'This role states no education requirement to compare against.');
        }
        if ($have === []) {
            return DimensionResult::unavailable(self::KEY, $label,
                'No education recorded on the candidate.',
                ['required' => $required]);
        }

        // The requirement is free text ("CA/MBA Finance", "B.Tech CS"). Split it into
        // qualifying terms and check how many the candidate's record satisfies.
        $terms = SkillMatcher::extractFromText($required);
        if ($terms === []) {
            $terms = [$required];
        }

        $cover = SkillMatcher::coverage($terms, $have);
        $score = (count($cover['matched']) / count($terms)) * 100;

        return DimensionResult::scored(self::KEY, $label, $score, sprintf(
            '%d of %d education requirement term%s met (requirement: %s).',
            count($cover['matched']), count($terms), count($terms) === 1 ? '' : 's', $required
        ), [
            'required'  => $required,
            'terms'     => $terms,
            'matched'   => $cover['matched'],
            'missing'   => $cover['missing'],
            'candidate' => $have,
        ]);
    }

    /** @return string[] flattened degree / field / institution strings */
    private function candidateEducation(HrCandidate $candidate): array
    {
        $out = [];
        foreach ((array) ($candidate->education ?? []) as $e) {
            if (is_array($e)) {
                foreach (['degree', 'field', 'specialization', 'institution', 'qualification'] as $k) {
                    if (! empty($e[$k])) {
                        $out[] = (string) $e[$k];
                    }
                }
            } elseif (is_string($e) && trim($e) !== '') {
                $out[] = $e;
            }
        }

        return array_values(array_unique($out));
    }
}
