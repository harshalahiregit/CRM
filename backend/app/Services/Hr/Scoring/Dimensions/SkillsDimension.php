<?php

namespace App\Services\Hr\Scoring\Dimensions;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Services\Hr\Scoring\SkillMatcher;

/**
 * Coverage of the role's REQUIRED skills.
 *
 * Two corrections to the old behaviour:
 *  - the denominator is the required-skill count, not the candidate's own skill
 *    count. Previously, listing more skills lowered your score.
 *  - matching is normalised (see SkillMatcher), not raw substring.
 *
 * Structured `hr_manpower_requests.required_skills` is preferred; the free-text
 * `hr_job_postings.requirements` blurb is a fallback. With neither, there is no
 * requirement to measure against, so the dimension is unavailable — it does NOT
 * fall back to a 50% baseline.
 */
class SkillsDimension
{
    public const KEY = 'skills';

    public function score(HrCandidate $candidate, ?HrJobPosting $job): DimensionResult
    {
        $label = 'Skills';
        $candidateSkills = array_values(array_filter((array) ($candidate->skills ?? [])));

        [$required, $source] = $this->requiredSkills($job);

        if ($required === []) {
            return DimensionResult::unavailable(self::KEY, $label,
                'This role lists no required skills, so there is nothing to match against.');
        }
        if ($candidateSkills === []) {
            return DimensionResult::unavailable(self::KEY, $label,
                'No skills recorded on the candidate.',
                ['required' => $required, 'required_source' => $source]);
        }

        $cover  = SkillMatcher::coverage($required, $candidateSkills);
        $score  = (count($cover['matched']) / count($required)) * 100;

        return DimensionResult::scored(self::KEY, $label, $score, sprintf(
            '%d of %d required skill%s matched (%s).',
            count($cover['matched']),
            count($required),
            count($required) === 1 ? '' : 's',
            $cover['missing'] === [] ? 'none missing' : 'missing: '.implode(', ', $cover['missing'])
        ), [
            'required'        => $required,
            'required_source' => $source,
            'matched'         => $cover['matched'],
            'missing'         => $cover['missing'],
            'candidate'       => $candidateSkills,
        ]);
    }

    /** @return array{0: string[], 1: string} */
    private function requiredSkills(?HrJobPosting $job): array
    {
        if (! $job) {
            return [[], 'none'];
        }

        $req = $job->manpowerRequest?->required_skills;
        if (is_string($req)) {
            $req = json_decode($req, true);
        }
        $req = array_values(array_filter(array_map('trim', (array) ($req ?: []))));
        if ($req !== []) {
            return [$req, 'manpower_request.required_skills'];
        }

        // Only accept the free-text blurb when it actually reads as a skill list.
        // "CA/MBA Finance, 3+ years" is an education + experience requirement, and
        // mining it for skills is what scored a qualified Financial Analyst at 0%.
        $parsed = SkillMatcher::extractSkillList($job->requirements);

        return $parsed !== null
            ? [$parsed, 'job_posting.requirements (parsed skill list)']
            : [[], 'none'];
    }
}
