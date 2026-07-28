<?php

namespace App\Services\Hr\Scoring\Dimensions;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use Carbon\Carbon;

/**
 * How soon the candidate can start, against the role's target joining date.
 *
 * Entirely new — `notice_period` was captured on 11 of 30 candidates and read by
 * nothing. Free text that cannot be parsed is missing data, not zero.
 */
class NoticePeriodDimension
{
    public const KEY = 'notice';

    public function score(HrCandidate $candidate, ?HrJobPosting $job): DimensionResult
    {
        $label = 'Notice Period';

        $days = $this->parseDays($candidate->notice_period);
        if ($days === null) {
            $raw = trim((string) ($candidate->notice_period ?? ''));

            return DimensionResult::unavailable(self::KEY, $label, $raw === ''
                ? 'No notice period recorded on the candidate.'
                : sprintf('Notice period "%s" could not be interpreted as a duration.', $raw));
        }

        $targetDays = $this->targetDays($job);
        if ($targetDays === null) {
            return DimensionResult::unavailable(self::KEY, $label,
                'This role states no target joining date to compare against.',
                ['notice_days' => $days]);
        }

        if ($days <= $targetDays) {
            return DimensionResult::scored(self::KEY, $label, 100, sprintf(
                'Available in %d day(s), within the %d day joining window.', $days, $targetDays
            ), ['notice_days' => $days, 'target_days' => $targetDays]);
        }

        // Late: score decays with the overrun. Double the window reaches 0.
        $overrun = ($days - $targetDays) / max($targetDays, 1);
        $score   = max(0, 100 - $overrun * 100);

        return DimensionResult::scored(self::KEY, $label, $score, sprintf(
            'Available in %d day(s), %d day(s) after the %d day joining window.',
            $days, $days - $targetDays, $targetDays
        ), ['notice_days' => $days, 'target_days' => $targetDays, 'overrun_days' => $days - $targetDays]);
    }

    /** "30 days", "2 months", "immediate", "60" -> days. Null when unparseable. */
    private function parseDays($raw): ?int
    {
        $s = strtolower(trim((string) $raw));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, 'immediate') || str_contains($s, 'available now') || $s === '0') {
            return 0;
        }
        if (! preg_match('/(\d+(?:\.\d+)?)/', $s, $m)) {
            return null;
        }
        $n = (float) $m[1];

        if (str_contains($s, 'month')) {
            return (int) round($n * 30);
        }
        if (str_contains($s, 'week')) {
            return (int) round($n * 7);
        }

        // Bare numbers and anything saying "day" are days.
        return (int) round($n);
    }

    /** Days from now until the requisition's target joining date. */
    private function targetDays(?HrJobPosting $job): ?int
    {
        $target = $job?->manpowerRequest?->target_joining_date;
        if (! $target) {
            return null;
        }
        try {
            $days = (int) ceil(Carbon::now()->startOfDay()->diffInDays(Carbon::parse($target)->startOfDay(), false));
        } catch (\Throwable) {
            return null;
        }

        // A target already in the past means the role is needed now.
        return max(0, $days);
    }
}
