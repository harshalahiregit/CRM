<?php

namespace App\Services\Hr;

use App\Support\Hr\JdAnalyzer;

/**
 * Adapter over the existing heuristic JdAnalyzer. It does NOT re-implement any
 * analysis — it reuses JdAnalyzer for completeness / readability / sections /
 * missing skills & keywords / suggestions, then DERIVES the enterprise scorecard
 * the Convert-to-JD screen needs (ATS, SEO, Skill Coverage, Overall) plus a
 * lightweight inclusive-language (bias) pass that JdAnalyzer doesn't cover.
 */
class JobDescriptionAnalyzerService
{
    /** Non-inclusive / exclusionary phrases to flag (lower-case). */
    private const BIAS_TERMS = [
        'young', 'youthful', 'energetic', 'recent graduate', 'digital native', 'rockstar', 'ninja', 'guru',
        'salesman', 'saleswoman', 'chairman', 'manpower', 'he/she', 'his/her', 'aggressive', 'dominant',
        'able-bodied', 'fresh out of', 'mature', 'high-energy',
    ];

    public function report(string $title, ?string $description, ?string $requirements, ?string $department = null, array $requiredSkills = []): array
    {
        // Reuse the existing analyzer — single source of truth for core signals.
        $a = (new JdAnalyzer)->analyze($title, (string) $description, (string) $requirements, $department);

        $text        = strtolower(trim((string) $description."\n".(string) $requirements));
        $completeness = (int) ($a['completeness'] ?? 0);
        $readability  = (int) ($a['readability'] ?? 0);
        $wordCount    = (int) ($a['word_count'] ?? 0);
        $present      = $a['present_sections'] ?? [];

        $lengthScore = min(100, (int) round(($wordCount / 250) * 100));

        // Skill coverage against the requisition's actual required skills.
        $skillCoverage = $this->skillCoverage($text, $requiredSkills, $a['missing_skills'] ?? []);
        $missingRequired = array_values(array_filter($requiredSkills, fn ($s) => $s && ! str_contains($text, strtolower($s))));

        // Derived enterprise scores (weights tuned to the available signals).
        $ats = (int) round(0.50 * $completeness + 0.30 * $lengthScore + 0.20 * $readability);
        $seo = (int) round(0.60 * $completeness + 0.40 * $skillCoverage);
        $bias = $this->biasCheck($text);

        $overall = (int) round(0.30 * $ats + 0.20 * $seo + 0.20 * $skillCoverage + 0.15 * $completeness + 0.15 * $readability);
        if ($bias['status'] !== 'Pass') {
            $overall = max(0, $overall - 6);
        }

        $suggestions = array_values(array_unique(array_merge($a['suggestions'] ?? [], $bias['suggestions'])));

        return [
            'ats_score'        => $ats,
            'seo_score'        => $seo,
            'skill_coverage'   => $skillCoverage,
            'completeness'     => $completeness,
            'readability'      => $readability,
            'bias'             => ['status' => $bias['status'], 'flagged' => $bias['flagged']],
            'overall_score'    => $overall,
            'suggestions'      => $suggestions,
            'missing_skills'   => $missingRequired ?: ($a['missing_skills'] ?? []),
            'missing_keywords' => $a['missing_keywords'] ?? [],
            'present_sections' => $present,
            'word_count'       => $wordCount,
            'ready_to_publish' => $a['ready_to_publish'] ?? false,
            'recommendation'   => $a['recommendation'] ?? null,
            // Pre-publish warnings (frontend confirms before publishing anyway).
            'warnings'         => $this->warnings($ats, $missingRequired, $present, $wordCount),
        ];
    }

    private function skillCoverage(string $text, array $requiredSkills, array $missingCommon): int
    {
        $requiredSkills = array_values(array_filter($requiredSkills));
        if ($requiredSkills) {
            $present = array_filter($requiredSkills, fn ($s) => str_contains($text, strtolower($s)));

            return (int) round((count($present) / count($requiredSkills)) * 100);
        }

        // No explicit skills — approximate from the analyzer's common-skill gaps.
        return max(0, 100 - min(100, count($missingCommon) * 8));
    }

    private function biasCheck(string $text): array
    {
        $flagged = array_values(array_filter(self::BIAS_TERMS, fn ($t) => str_contains($text, $t)));

        return [
            'status'      => $flagged ? 'Warn' : 'Pass',
            'flagged'     => $flagged,
            'suggestions' => $flagged ? ['Consider replacing non-inclusive terms: '.implode(', ', $flagged)] : [],
        ];
    }

    private function warnings(int $ats, array $missingSkills, array $present, int $wordCount): array
    {
        $w = [];
        if ($ats < 70) {
            $w[] = "ATS score is low ({$ats}%).";
        }
        if ($missingSkills) {
            $w[] = 'Some required skills are not mentioned: '.implode(', ', array_slice($missingSkills, 0, 6)).'.';
        }
        if (! in_array('responsibilities', $present, true)) {
            $w[] = 'No clear Responsibilities section.';
        }
        if (! in_array('requirements', $present, true)) {
            $w[] = 'No Qualifications / Requirements section.';
        }
        if (! in_array('experience', $present, true)) {
            $w[] = 'No Experience section.';
        }
        if ($wordCount < 120) {
            $w[] = "Description looks too short ({$wordCount} words).";
        }

        return $w;
    }
}
