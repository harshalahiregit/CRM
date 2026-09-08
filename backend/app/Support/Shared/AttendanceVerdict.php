<?php

namespace App\Support\Shared;

use Illuminate\Support\Carbon;

/**
 * The organiser's three slabs.
 *
 * `attendance_status` already existed and had no defined vocabulary — rows in
 * the wild carry Present, Late, Absent, Excused, Online and empty string, some
 * typed by a person and some written by the join recorder. It is the ATTENDEE's
 * side of the record and it stays that way.
 *
 * This is the ORGANISER's side, and it is deliberately a small, closed set. The
 * question being answered is not "what happened" in general but "do I accept
 * this person's claim to have attended", and there are only three useful
 * answers.
 *
 * NULL — no verdict — is a fourth state and a real one: nobody has reviewed this
 * person yet. It must never be conflated with Complete_Absent, or an unreviewed
 * meeting would read as one where nobody turned up.
 */
final class AttendanceVerdict
{
    /** They were there for the meeting. */
    public const FULLY_PRESENT = 'Fully_Present';

    /** They were there for part of it — see the window. */
    public const PARTIAL_ABSENT = 'Partial_Absent';

    /** They were not there, whatever the CRM record claims. */
    public const COMPLETE_ABSENT = 'Complete_Absent';

    public const ALL = [self::FULLY_PRESENT, self::PARTIAL_ABSENT, self::COMPLETE_ABSENT];

    public const LABELS = [
        self::FULLY_PRESENT => 'Fully Present',
        self::PARTIAL_ABSENT => 'Partial Absent',
        self::COMPLETE_ABSENT => 'Complete Absent',
    ];

    /** The slabs that count as having attended at all. */
    public const ATTENDING = [self::FULLY_PRESENT, self::PARTIAL_ABSENT];

    public static function isValid(?string $verdict): bool
    {
        return $verdict !== null && in_array($verdict, self::ALL, true);
    }

    public static function label(?string $verdict): ?string
    {
        return $verdict === null ? null : (self::LABELS[$verdict] ?? $verdict);
    }

    /**
     * Only a partial verdict carries a window.
     *
     * "Partial" without times says a person was unhappy, not what happened —
     * the whole value of the slab is being able to write down that somebody
     * joined at 9:30 and left at 10:00 on a meeting booked until 11:00.
     */
    public static function needsWindow(?string $verdict): bool
    {
        return $verdict === self::PARTIAL_ABSENT;
    }

    /** Minutes actually attended, when a window was given. */
    public static function minutes(?Carbon $from, ?Carbon $to): ?int
    {
        if (! $from || ! $to || $to->lessThanOrEqualTo($from)) {
            return null;
        }

        return (int) $from->diffInMinutes($to);
    }

    /**
     * Does the organiser's verdict contradict what the person claimed?
     *
     * This is the signal the whole review exists to surface: somebody marked
     * attendance in the CRM — which is how they got the joining link at all —
     * and the organiser says they never appeared. It is computed rather than
     * stored, so it cannot drift out of step with the two records it compares.
     */
    public static function contradictsClaim(bool $claimedAttended, ?string $verdict): bool
    {
        return $claimedAttended && $verdict === self::COMPLETE_ABSENT;
    }
}
