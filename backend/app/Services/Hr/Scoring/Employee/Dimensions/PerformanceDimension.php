<?php

namespace App\Services\Hr\Scoring\Employee\Dimensions;

use App\Models\Hr\HrEmployee;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;

/**
 * #39 — appraisal ratings, the most direct measure of how someone is doing.
 *
 * Reads `hr_performance_reviews.overall_rating`, which is already captured by the
 * Performance module. Only reviews that reached a terminal state count: a draft
 * rating is one manager's work in progress, not a judgement the company has made.
 */
class PerformanceDimension implements EmployeeDimension
{
    public const KEY = 'performance';

    /** Ratings are captured out of 5 by the Performance module. */
    private const RATING_MAX = 5.0;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Performance Reviews';
    }

    public function score(HrEmployee $employee, array $ctx): DimensionResult
    {
        $reviews = collect($ctx['reviews'] ?? [])
            ->filter(fn ($r) => $r->overall_rating !== null);

        if ($reviews->isEmpty()) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'No completed performance review with a rating yet.');
        }

        // The most recent review carries the most weight, but a single good or bad
        // quarter should not define someone — so recent and average are blended.
        $sorted  = $reviews->sortByDesc(fn ($r) => [$r->period_year, $r->period_month])->values();
        $latest  = (float) $sorted->first()->overall_rating;
        $average = (float) $reviews->avg('overall_rating');

        $blended = ($latest * 0.6) + ($average * 0.4);
        $score   = ($blended / self::RATING_MAX) * 100;

        return DimensionResult::scored(self::KEY, $this->label(), $score,
            sprintf('Latest rating %.1f/5 across %d review(s), average %.1f/5.',
                $latest, $reviews->count(), $average),
            [
                'latest_rating'  => round($latest, 2),
                'average_rating' => round($average, 2),
                'review_count'   => $reviews->count(),
                'latest_period'  => $sorted->first()->period_label,
            ]);
    }
}
