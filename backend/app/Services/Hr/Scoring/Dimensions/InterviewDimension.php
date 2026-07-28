<?php

namespace App\Services\Hr\Scoring\Dimensions;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;

/**
 * Interview panel feedback.
 *
 * Entirely new — interview scores existed but never reached ai_score, so a candidate
 * could interview brilliantly and their "AI score" would not move.
 *
 * Completion is detected via status/result, NOT `completed_at`: that column is NULL
 * on all 34 existing rounds, so keying off it would silently score nothing.
 */
class InterviewDimension
{
    public const KEY = 'interview';

    /** Round statuses that mean the panel has actually met. */
    private const DONE = ['completed', 'complete', 'done', 'passed', 'failed', 'cleared', 'rejected'];

    public function score(HrCandidate $candidate, ?HrJobPosting $job): DimensionResult
    {
        $label = 'Interview';

        $rounds = $candidate->relationLoaded('interviewRounds')
            ? $candidate->interviewRounds
            : $candidate->interviewRounds()->get();

        $scored = [];
        foreach ($rounds as $r) {
            if (! $this->isDone($r)) {
                continue;
            }
            $v = $this->roundScore($r);
            if ($v !== null) {
                $scored[] = ['round' => $r->round_name ?? ('Round '.$r->id), 'score' => $v];
            }
        }

        if ($scored === []) {
            return DimensionResult::unavailable(self::KEY, $label,
                'No completed interview round carries a score yet.',
                ['round_count' => is_countable($rounds) ? count($rounds) : 0]);
        }

        $avg = array_sum(array_column($scored, 'score')) / count($scored);

        return DimensionResult::scored(self::KEY, $label, $avg, sprintf(
            'Average of %d scored interview round(s): %d%%.', count($scored), (int) round($avg)
        ), ['rounds' => $scored]);
    }

    private function isDone($round): bool
    {
        $status = strtolower(trim((string) ($round->status ?? '')));
        $result = strtolower(trim((string) ($round->result ?? '')));

        return in_array($status, self::DONE, true)
            || in_array($result, self::DONE, true)
            || $round->completed_at !== null;
    }

    /**
     * Prefer the panel's explicit overall score; fall back to the 1-5 star rating;
     * then to the mean of whatever component scores were filled in.
     */
    private function roundScore($round): ?float
    {
        if ($round->overall_score !== null && $round->overall_score !== '') {
            return (float) $round->overall_score;
        }

        if ($round->rating !== null && $round->rating !== '' && (float) $round->rating > 0) {
            return min(100, ((float) $round->rating / 5) * 100);
        }

        $components = [];
        foreach ([
            'technical_score', 'communication_score', 'problem_solving_score', 'knowledge_score',
            'confidence_score', 'ownership_score', 'learning_ability_score', 'decision_making_score',
            'leadership_score', 'integrity_score', 'culture_fit_score',
        ] as $c) {
            $v = $round->{$c} ?? null;
            if ($v !== null && $v !== '') {
                $components[] = (float) $v;
            }
        }

        return $components === [] ? null : array_sum($components) / count($components);
    }
}
