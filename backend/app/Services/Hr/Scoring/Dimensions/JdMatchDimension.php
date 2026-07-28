<?php

namespace App\Services\Hr\Scoring\Dimensions;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Services\Hr\Scoring\SkillMatcher;

/**
 * Breadth of overlap between the candidate's profile and the full job description,
 * including PREFERRED (nice-to-have) skills — where SkillsDimension measures only
 * the required set.
 *
 * The old version had a floor of 45: zero keyword hits still scored 45/100. That
 * floor is gone. When the JD carries no comparable text the dimension is
 * unavailable rather than neutral-scored.
 */
class JdMatchDimension
{
    public const KEY = 'jd';

    public function score(HrCandidate $candidate, ?HrJobPosting $job): DimensionResult
    {
        $label = 'JD Match';

        if (! $job) {
            return DimensionResult::unavailable(self::KEY, $label, 'Candidate is not linked to a job posting.');
        }

        $candidateSkills = array_values(array_filter((array) ($candidate->skills ?? [])));
        if ($candidateSkills === []) {
            return DimensionResult::unavailable(self::KEY, $label, 'No skills recorded on the candidate.');
        }

        $terms = $this->jdTerms($job);
        if ($terms === []) {
            return DimensionResult::unavailable(self::KEY, $label,
                'The job description carries no comparable text or skill list.');
        }

        // Share of JD terms the candidate demonstrably covers.
        $cover = SkillMatcher::coverage($terms, $candidateSkills);
        $score = (count($cover['matched']) / count($terms)) * 100;

        return DimensionResult::scored(self::KEY, $label, $score, sprintf(
            '%d of %d job-description term%s covered by the candidate profile.',
            count($cover['matched']), count($terms), count($terms) === 1 ? '' : 's'
        ), [
            'jd_terms' => $terms,
            'matched'  => $cover['matched'],
            'missing'  => $cover['missing'],
        ]);
    }

    /** @return string[] required + preferred + parsed description/requirements text */
    private function jdTerms(HrJobPosting $job): array
    {
        $mr = $job->manpowerRequest;
        $terms = [];

        foreach (['required_skills', 'preferred_skills'] as $field) {
            $v = $mr?->{$field};
            if (is_string($v)) {
                $v = json_decode($v, true);
            }
            foreach ((array) ($v ?: []) as $s) {
                $terms[] = (string) $s;
            }
        }

        // Structured required/preferred skills above are authoritative and taken as
        // given. Free text is not: the same degree/experience/salary phrases that
        // corrupt SkillsDimension corrupt this one, so fragments must pass the
        // skill-list guard before they become comparable terms.
        $freeText = trim(($job->requirements ?? '').' , '.($job->description ?? ''));
        foreach (SkillMatcher::extractFromText($freeText) as $s) {
            if (SkillMatcher::looksLikeSkill($s)) {
                $terms[] = $s;
            }
        }

        // De-duplicate on the canonical form so "React.js" and "React" count once.
        $seen = [];
        $out  = [];
        foreach ($terms as $t) {
            $t = trim($t);
            if ($t === '') {
                continue;
            }
            $c = SkillMatcher::canonical($t);
            if ($c === '' || isset($seen[$c])) {
                continue;
            }
            $seen[$c] = true;
            $out[] = $t;
        }

        return $out;
    }
}
