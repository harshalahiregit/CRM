<?php

namespace App\Support\Shared;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When issued safety gear stops counting.
 *
 * A PPE rule may carry `replacement_frequency_days`: a helmet is good for so
 * many days, a harness for fewer. The field has been on both engines' matrices,
 * on both settings screens and in both API payloads since the matrix was built
 * — and nothing has ever read it. A helmet issued three years ago satisfied the
 * badge check exactly as well as one issued this morning, which is the opposite
 * of what a replacement interval is for.
 *
 * The rule is deliberately tiny and shared, because the two engines must agree:
 * a worker's gear cannot be expired on one side of the site and current on the
 * other.
 *
 * No interval set means no expiry — most rules will not set one, and a blank
 * field must never start blocking badges that were fine yesterday.
 */
final class PpeReplacement
{
    /** The day an issue falls due, or null when the rule sets no interval. */
    public static function dueOn($issuedDate, ?int $frequencyDays): ?Carbon
    {
        if (! $issuedDate || ! $frequencyDays || $frequencyDays < 1) {
            return null;
        }

        return Carbon::parse($issuedDate)->startOfDay()->addDays($frequencyDays);
    }

    /**
     * Past its replacement date.
     *
     * Due *today* is not yet expired — the worker has the day to swap it, and
     * turning someone away at the gate on the morning their interval lands
     * would be a surprise nobody configured.
     */
    public static function isExpired($issuedDate, ?int $frequencyDays, ?CarbonInterface $asOf = null): bool
    {
        $due = self::dueOn($issuedDate, $frequencyDays);

        if (! $due) {
            return false;
        }

        return ($asOf ? Carbon::parse($asOf) : Carbon::now())->startOfDay()->greaterThan($due);
    }

    /** Days left before it falls due; negative once overdue, null when no interval. */
    public static function daysRemaining($issuedDate, ?int $frequencyDays, ?CarbonInterface $asOf = null): ?int
    {
        $due = self::dueOn($issuedDate, $frequencyDays);

        if (! $due) {
            return null;
        }

        return (int) ($asOf ? Carbon::parse($asOf) : Carbon::now())->startOfDay()->diffInDays($due, false);
    }
}
