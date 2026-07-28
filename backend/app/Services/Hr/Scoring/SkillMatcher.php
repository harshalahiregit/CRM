<?php

namespace App\Services\Hr\Scoring;

/**
 * Skill normalisation and matching.
 *
 * The old scorer did `str_contains($job->requirements, $skill)`, which failed in
 * both directions at once: "React.js" is not a substring of "5+ years React,
 * TypeScript, Node.js", so a React developer scored 40%; and "Financial Analysis"
 * is not a substring of "CA/MBA Finance", so a Financial Analyst scored 0% against
 * a Financial Analyst requisition.
 *
 * Matching here is: normalise both sides → exact match on the canonical form →
 * alias lookup → token-subset containment (so "React" matches "React Developer"
 * but not, say, "Native"). Substring matching on raw strings is never used.
 */
final class SkillMatcher
{
    /**
     * Canonical forms for spellings that mean the same skill. Keys are already
     * normalised (lowercase, alphanumerics only).
     */
    private const ALIASES = [
        'reactjs' => 'react', 'reactjs2' => 'react', 'reactnative' => 'react native',
        'nodejs' => 'node', 'node' => 'node', 'vuejs' => 'vue', 'angularjs' => 'angular',
        'nextjs' => 'next', 'nuxtjs' => 'nuxt', 'expressjs' => 'express',
        'js' => 'javascript', 'ts' => 'typescript', 'py' => 'python',
        'golang' => 'go', 'cpp' => 'c++', 'csharp' => 'c#', 'dotnet' => '.net',
        'postgres' => 'postgresql', 'psql' => 'postgresql', 'mongo' => 'mongodb',
        'k8s' => 'kubernetes', 'gcp' => 'google cloud', 'aws' => 'amazon web services',
        'ml' => 'machine learning', 'ai' => 'artificial intelligence',
        'restapi' => 'rest', 'restapis' => 'rest', 'restfulapi' => 'rest',
        'ci' => 'ci/cd', 'cicd' => 'ci/cd',
        'hrms' => 'hris', 'recruitment' => 'recruiting',
        // NOTE: only spelling variants belong here. Mapping a SPECIALISATION to a
        // broader term (e.g. 'b2bsales' => 'sales') destroys tokens and, under the
        // directional containment rule in matches(), makes the specific skill fail
        // its own requirement — "B2B Sales" stopped matching a "B2B sales"
        // requirement. Semantic broadening is not aliasing.
    ];

    /** Noise words stripped before token comparison. */
    private const STOPWORDS = ['and', 'or', 'the', 'with', 'for', 'in', 'of', 'a', 'an', 'to', 'years', 'year', 'experience', 'plus'];

    /**
     * Qualification markers. A fragment carrying one of these describes a DEGREE,
     * not a skill — "CA/MBA Finance" is an education requirement, and treating it as
     * a required skill scored a qualified Financial Analyst at 0%.
     */
    private const EDUCATION_MARKERS = [
        'ca', 'cs', 'cma', 'icwa', 'mba', 'bba', 'bca', 'mca', 'btech', 'mtech', 'be', 'me',
        'bsc', 'msc', 'ba', 'ma', 'bcom', 'mcom', 'phd', 'llb', 'llm', 'bachelor', 'bachelors',
        'master', 'masters', 'degree', 'diploma', 'graduate', 'graduation', 'postgraduate',
        'undergraduate', 'qualification', 'qualified', 'certification', 'certified',
    ];

    /** Compensation markers — never a skill. */
    private const SALARY_MARKERS = ['lpa', 'ctc', 'salary', 'inr', 'usd', 'compensation', 'budget', 'package', 'stipend'];

    /**
     * Words that only ever name a ROLE. A fragment is rejected only when EVERY token
     * is one of these, so "Sales Executive" survives on "sales" while a bare
     * "Executive" does not.
     */
    private const TITLE_WORDS = [
        'executive', 'manager', 'officer', 'associate', 'assistant', 'intern', 'trainee',
        'consultant', 'specialist', 'lead', 'head', 'director', 'coordinator', 'analyst',
        'engineer', 'developer', 'senior', 'junior', 'mid', 'level', 'fresher', 'candidate',
        'preferred', 'required', 'mandatory', 'must', 'have', 'good', 'strong', 'excellent',
        'knowledge', 'skills', 'skill', 'ability', 'proficiency', 'proficient', 'hands', 'on',
    ];

    /** Lowercase, strip punctuation, collapse whitespace, then resolve aliases. */
    public static function canonical(string $skill): string
    {
        $s = strtolower(trim($skill));
        // Keep + and # (c++, c#) and . for .net, drop everything else to spaces.
        $s = preg_replace('/[^a-z0-9+#.\s]/', ' ', $s) ?? '';
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');

        // Alias lookup uses the fully-squashed form so "React.js" / "React JS" /
        // "reactjs" all resolve to the same key.
        $squashed = preg_replace('/[^a-z0-9+#]/', '', $s) ?? '';

        return self::ALIASES[$squashed] ?? $s;
    }

    /** @return string[] meaningful tokens of a canonical skill */
    public static function tokens(string $skill): array
    {
        $canonical = self::canonical($skill);

        return array_values(array_filter(
            preg_split('/\s+/', $canonical) ?: [],
            fn ($t) => $t !== '' && ! in_array($t, self::STOPWORDS, true)
        ));
    }

    /** Do two skill strings refer to the same skill? */
    public static function matches(string $required, string $candidate): bool
    {
        $r = self::canonical($required);
        $c = self::canonical($candidate);

        if ($r === '' || $c === '') {
            return false;
        }
        if ($r === $c) {
            return true;
        }

        $rt = self::tokens($required);
        $ct = self::tokens($candidate);
        if ($rt === [] || $ct === []) {
            return false;
        }

        // DIRECTIONAL containment: every token of the REQUIRED skill must appear in
        // the candidate's skill, not the reverse. The direction matters — a role
        // requiring "React Native" is not satisfied by a candidate listing "React",
        // whereas "React Developer" does satisfy a "React" requirement. A symmetric
        // check credits the first case and overstates the match.
        foreach ($rt as $t) {
            if (! in_array($t, $ct, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Which required skills the candidate has.
     *
     * @param  string[]  $required
     * @param  string[]  $candidateSkills
     * @return array{matched: string[], missing: string[]}
     */
    public static function coverage(array $required, array $candidateSkills): array
    {
        $matched = [];
        $missing = [];

        foreach ($required as $req) {
            $req = trim((string) $req);
            if ($req === '') {
                continue;
            }
            $hit = false;
            foreach ($candidateSkills as $have) {
                if (self::matches($req, (string) $have)) {
                    $hit = true;
                    break;
                }
            }
            $hit ? $matched[] = $req : $missing[] = $req;
        }

        return ['matched' => $matched, 'missing' => $missing];
    }

    /**
     * Is this fragment actually naming a skill?
     *
     * Rejects degree requirements, bare experience/salary phrases and pure job
     * titles. Deliberately conservative: a fragment survives if it carries at least
     * one token that is not a qualification, a compensation term or a title word.
     */
    public static function looksLikeSkill(string $fragment): bool
    {
        $tokens = self::tokens($fragment);
        if ($tokens === []) {
            return false;   // was purely "3+ years" / filler
        }

        foreach ($tokens as $t) {
            // One degree or compensation marker disqualifies the whole fragment:
            // "MBA Finance" is a qualification, not the skill "finance".
            if (in_array($t, self::EDUCATION_MARKERS, true) || in_array($t, self::SALARY_MARKERS, true)) {
                return false;
            }
        }

        // Reject only if EVERY token is a role/filler word.
        foreach ($tokens as $t) {
            if (! in_array($t, self::TITLE_WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Free-text requirements → a skill list, or NULL when the text is not one.
     *
     * The fallback used when a requisition carries no structured required_skills.
     * Returning null matters: "CA/MBA Finance, 3+ years" is an education-and-
     * experience requirement, and mining it for "skills" produced a 0% skills score
     * for a candidate who was in fact qualified. No skill list means the dimension
     * has nothing to measure, not that the candidate failed it.
     *
     * @return string[]|null
     */
    public static function extractSkillList(?string $text): ?array
    {
        $candidates = self::extractFromText($text);
        if ($candidates === []) {
            return null;
        }

        $skills = array_values(array_filter($candidates, fn ($f) => self::looksLikeSkill($f)));

        // Not a single fragment named a skill — the text is describing something else.
        return $skills === [] ? null : $skills;
    }

    /**
     * Split a free-text blurb into candidate fragments. Raw output — callers that
     * need a *validated* skill list must use extractSkillList(), which filters out
     * degrees, experience phrases, salaries and job titles.
     *
     * @return string[]
     */
    public static function extractFromText(?string $text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [];
        }

        // Requirements are written as comma / newline / bullet separated fragments.
        $parts = preg_split('/[,;\n\r•|\/]+/', $text) ?: [];
        $out = [];
        foreach ($parts as $p) {
            // Drop leading experience qualifiers: "5+ years React" -> "React".
            $p = preg_replace('/^\s*\d+\+?\s*(years?|yrs?)\s*(of)?\s*/i', '', trim($p)) ?? '';
            $p = trim($p);
            if ($p === '' || mb_strlen($p) > 40) {
                continue;
            }
            if (self::tokens($p) === []) {
                continue;
            }
            $out[] = $p;
        }

        return array_values(array_unique($out));
    }
}
