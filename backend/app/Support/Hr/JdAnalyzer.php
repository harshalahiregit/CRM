<?php

namespace App\Support\Hr;

/**
 * Heuristic JD quality analyzer (reuses the same rule-based "AI" approach as the
 * candidate scoring). No external LLM required; returns a quality score plus
 * missing skills/keywords, suggestions, readability and completeness so HR gets
 * actionable feedback while writing a Job Description.
 */
class JdAnalyzer
{
    /** Sections a strong JD is expected to contain. */
    private const SECTIONS = [
        'responsibilities' => ['responsib', 'you will', 'duties', 'role involves', 'day to day'],
        'requirements'     => ['require', 'must have', 'qualif', 'experience in', 'proficient'],
        'skills'           => ['skill', 'proficient', 'knowledge of', 'familiar'],
        'experience'       => ['year', 'experience'],
        'benefits'         => ['benefit', 'perk', 'we offer', 'compensation', 'salary'],
        'company'          => ['about us', 'our team', 'company', 'who we are'],
    ];

    /** Common role skills used to flag "missing skills" when absent from the JD. */
    private const COMMON_SKILLS = [
        'communication', 'problem solving', 'teamwork', 'leadership', 'ownership',
        'sql', 'api', 'testing', 'agile', 'git', 'cloud', 'analytics',
    ];

    /** Keywords that make a posting discoverable / complete. */
    private const KEYWORDS = ['responsibilities', 'requirements', 'qualifications', 'experience', 'skills', 'location', 'benefits', 'about'];

    public function analyze(string $title, ?string $description, ?string $requirements, ?string $department = null): array
    {
        $text  = trim((string) $description."\n".(string) $requirements);
        $lower = strtolower($text);
        $words = preg_split('/\s+/', trim($lower)) ?: [];
        $wordCount = $text === '' ? 0 : count(array_filter($words));
        $sentences = max(1, preg_match_all('/[.!?]+/', $text));

        // ── Completeness: how many expected sections are present ──────────────
        $present = [];
        foreach (self::SECTIONS as $section => $cues) {
            foreach ($cues as $cue) {
                if (str_contains($lower, $cue)) {
                    $present[] = $section;
                    break;
                }
            }
        }
        $completeness = (int) round(count(array_unique($present)) / count(self::SECTIONS) * 100);

        // ── Readability: reward reasonable length + shorter sentences ─────────
        $avgSentenceLen = $wordCount / $sentences;
        $readability = (int) round(max(0, min(100,
            ($wordCount >= 80 ? 60 : $wordCount / 80 * 60) +          // enough content
            ($avgSentenceLen <= 22 ? 40 : max(0, 40 - ($avgSentenceLen - 22) * 2)) // not too dense
        )));

        // ── Missing skills / keywords ─────────────────────────────────────────
        $missingSkills = array_values(array_filter(self::COMMON_SKILLS, fn ($s) => ! str_contains($lower, $s)));
        $missingSkills = array_slice($missingSkills, 0, 6);
        $missingKeywords = array_values(array_filter(self::KEYWORDS, fn ($k) => ! str_contains($lower, $k)));

        // ── Suggestions ───────────────────────────────────────────────────────
        $suggestions = [];
        if ($wordCount < 80) {
            $suggestions[] = 'The description is short — aim for 80–250 words so candidates understand the role.';
        }
        foreach (['responsibilities' => 'a Responsibilities section', 'requirements' => 'a Requirements / Qualifications section', 'benefits' => 'a Benefits / What we offer section'] as $sec => $label) {
            if (! in_array($sec, $present, true)) {
                $suggestions[] = 'Add '.$label.'.';
            }
        }
        if ($missingKeywords) {
            $suggestions[] = 'Mention: '.implode(', ', array_slice($missingKeywords, 0, 4)).'.';
        }
        if ($avgSentenceLen > 24) {
            $suggestions[] = 'Break long sentences into bullet points for readability.';
        }
        if (! $suggestions) {
            $suggestions[] = 'Looks solid — you can still tailor skills to the exact tech stack.';
        }

        // ── Overall quality score ─────────────────────────────────────────────
        $lengthScore = min(100, $wordCount / 180 * 100);
        $quality = (int) round($completeness * 0.45 + $readability * 0.25 + $lengthScore * 0.30);

        $quality = max(0, min(100, $quality));

        // Recommendation shown to HR before publishing the job.
        [$recommendation, $ready] = match (true) {
            $quality >= 75 => ['Ready to publish', true],
            $quality >= 50 => ['Improve before publishing — key sections are thin', false],
            default        => ['Not ready — add responsibilities, requirements and detail', false],
        };

        return [
            'quality_score'    => $quality,
            'completeness'     => $completeness,
            'readability'      => $readability,
            'word_count'       => $wordCount,
            'missing_skills'   => $missingSkills,
            'missing_keywords' => array_slice($missingKeywords, 0, 6),
            'suggestions'      => $suggestions,
            'present_sections' => array_values(array_unique($present)),
            'recommendation'   => $recommendation,
            'ready_to_publish' => $ready,
        ];
    }

    /**
     * "Improve with AI" / "Regenerate" — return an enriched JD that keeps the HR
     * author's original text and appends the missing sections + skills. Purely
     * additive so nothing the author wrote is lost.
     */
    public function improve(string $title, ?string $description, ?string $requirements, ?string $department, array $analysis): string
    {
        $out = trim((string) $description);
        if ($out === '') {
            $out = "We are looking for a talented {$title}".($department ? " to join our {$department} team" : '').'.';
        }

        $present = $analysis['present_sections'] ?? [];
        if (! in_array('responsibilities', $present, true)) {
            $out .= "\n\nResponsibilities:\n- Own and deliver key {$title} outcomes\n- Collaborate cross-functionally with the team\n- Continuously improve quality and processes";
        }
        if (! in_array('requirements', $present, true)) {
            $out .= "\n\nRequirements:\n".trim((string) $requirements ?: "- Relevant experience in a similar {$title} role\n- Strong problem solving and communication skills");
        }
        if (! empty($analysis['missing_skills'])) {
            $out .= "\n\nGood to have: ".implode(', ', $analysis['missing_skills']).'.';
        }
        if (! in_array('benefits', $present, true)) {
            $out .= "\n\nWhat we offer:\n- Competitive compensation\n- Growth opportunities and a collaborative culture";
        }

        return $out;
    }
}
