<?php

namespace App\Support\Shared;

use Illuminate\Support\Carbon;

/**
 * Where a meeting sits against the clock — the single answer both engines give.
 *
 * `status` says what people decided (Draft, Scheduled, Delayed, Completed,
 * Cancelled). It says nothing about whether the meeting has actually happened,
 * so a meeting scheduled for last Tuesday that nobody closed still reported
 * itself as "Scheduled" — on the admin list, in the vendor portal, and on the
 * dashboards. The vendor was still offered a Join button for it.
 *
 * Timing is the other half, and it is DERIVED rather than stored: computed from
 * the clock on every read, so it is right the moment the end passes, with no
 * job to run and no column to fall out of date. The six states:
 *
 *   draft      not published — the vendor cannot see it at all
 *   upcoming   published, has not started
 *   live       under way
 *   ended      the call was held and has finished
 *   expired    the slot went by and the meeting was never held
 *   closed     Completed or Cancelled; the clock stopped mattering
 *
 * `ended` and `expired` both mean "over", and they are kept apart because they
 * are different news to the person reading them: one meeting happened, the
 * other was missed. Before there was any record of the call itself, every
 * meeting that finished early was reported as still running until its booked
 * hour was up — see state() for how the two are now told apart.
 *
 * Two clocks meet here, deliberately. The booked slot is a wall clock somebody
 * typed, so it is compared against the tenant's business clock ({@see
 * BusinessTime}) — that is what the stored value means. The record of the call
 * is a set of machine instants nobody typed, and those need no zone at all:
 * they are only ever asked whether they exist, and subtracted from each other.
 */
final class MeetingTiming
{
    public const DRAFT    = 'draft';
    public const UPCOMING = 'upcoming';
    public const LIVE     = 'live';
    public const ENDED    = 'ended';
    public const EXPIRED  = 'expired';
    public const CLOSED   = 'closed';

    public const LABELS = [
        self::DRAFT    => 'Draft',
        self::UPCOMING => 'Upcoming',
        self::LIVE     => 'In progress',
        self::ENDED    => 'Ended',
        self::EXPIRED  => 'Expired',
        self::CLOSED   => 'Closed',
    ];

    /**
     * States where the meeting is over and the join link should not be offered.
     *
     * `ended` and `expired` are both finished, and they are kept apart because
     * they are different news: `ended` means the call was held and finished,
     * `expired` means the slot went by and nobody turned up. Anything reading
     * "can this still be joined?" wants both.
     */
    public const FINISHED = [self::ENDED, self::EXPIRED];

    /**
     * A meeting with no end and no duration. Only reached by rows written
     * before end_at existed; without it such a meeting would never expire.
     */
    public const FALLBACK_MINUTES = 60;

    /**
     * When the meeting actually finishes.
     *
     * The stored end is the truth when it is present and after the start — the
     * duration column is derived from it, not the other way round. It is
     * ignored when it sits BEFORE the start, which only happens on a row whose
     * start was moved without its end (the bug this shipped with), and the
     * duration is used instead so the meeting still has a sane length.
     */
    public static function endsAt($start, $end, ?int $durationMinutes, ?int $tenantId = null): ?Carbon
    {
        $startsAt = BusinessTime::parse($start, $tenantId);
        if (! $startsAt) {
            return null;
        }

        $endsAt = BusinessTime::parse($end, $tenantId);
        if ($endsAt && $endsAt->greaterThan($startsAt)) {
            return $endsAt;
        }

        return $startsAt->copy()->addMinutes(max(1, (int) ($durationMinutes ?: self::FALLBACK_MINUTES)));
    }

    /**
     * The timing state.
     *
     * `$isClosed` and `$isDraft` are passed in rather than read here: the two
     * engines keep their own status vocabularies (KickoffStatus and
     * PurchaseKickoffStatus) and this deliberately does not know either.
     *
     * ── What actually happened outranks what was scheduled ──────────────
     * The slot is a plan. `$actualStart` and `$actualEnd` are the record of the
     * call itself, written by {@see \App\Services\Shared\MeetingPresence} as
     * people arrive and leave, and where the two disagree the record wins:
     *
     *   - a call that finished after seven minutes of a booked hour is ENDED,
     *     not "In progress" for the remaining fifty-three. That mismatch is
     *     what made the state untrustworthy — the screen insisted a meeting was
     *     running that everyone had just left.
     *   - a call still running past its slot is LIVE, not EXPIRED. Meetings
     *     overrun; a link withdrawn from people who are still talking is worse
     *     than one offered a few minutes late.
     *   - a call that started late is LIVE from the moment somebody arrived.
     *
     * With neither recorded — every meeting held before this existed, and every
     * one nobody joined through the room — the slot is all there is, and the
     * behaviour is exactly what it was.
     */
    public static function state(
        $start,
        $end,
        ?int $durationMinutes,
        bool $isDraft,
        bool $isClosed,
        ?int $tenantId = null,
        $actualStart = null,
        $actualEnd = null,
        $lastSeen = null,
    ): string {
        if ($isDraft) {
            return self::DRAFT;
        }
        if ($isClosed) {
            return self::CLOSED;
        }

        // The call finished. Nothing about the booked slot can override that.
        if ($actualEnd) {
            return self::ENDED;
        }

        // The call is under way — whether or not the slot has been reached, and
        // whether or not it has passed. These are machine instants, so they are
        // read on the machine clock, not the tenant's wall clock.
        if ($actualStart) {
            return self::isStale($lastSeen) ? self::ENDED : self::LIVE;
        }

        $now = BusinessTime::now($tenantId);

        $startsAt = BusinessTime::parse($start, $tenantId);
        if (! $startsAt) {
            // Published with no date on it. It cannot have passed, so it is
            // still ahead of everyone rather than expired.
            return self::UPCOMING;
        }

        if ($now->lessThan($startsAt)) {
            return self::UPCOMING;
        }

        $endsAt = self::endsAt($start, $end, $durationMinutes, $tenantId);

        return $endsAt && $now->greaterThanOrEqualTo($endsAt) ? self::EXPIRED : self::LIVE;
    }

    /**
     * How long a call may go unheard from before it counts as over.
     *
     * The room reports every 20 seconds, so this is roughly nine missed reports
     * — far beyond a dropped request or a moment of bad signal, and short enough
     * that a list of meetings is honest within a few minutes.
     */
    public const STALE_AFTER_MINUTES = 3;

    /**
     * Has the call gone quiet?
     *
     * A meeting is only ever ENDED explicitly when somebody hangs up or leaves
     * the room. Calls do not always end that way: a laptop is shut, a browser
     * crashes, somebody clicks away to another page. Without this the meeting
     * would then read "In progress" for ever — worse than the bug this replaced,
     * which at least stopped at the end of the booked hour.
     *
     * A meeting with no heartbeat at all is NOT treated as stale: that is a row
     * from before any of this existed, and it must keep behaving as it did.
     */
    private static function isStale($lastSeen): bool
    {
        if (! $lastSeen) {
            return false;
        }

        return Carbon::parse($lastSeen)->addMinutes(self::STALE_AFTER_MINUTES)->isPast();
    }

    /**
     * How long the call actually ran, in minutes — null until it has ended.
     *
     * Measured start-to-finish rather than summed per person: "the meeting ran
     * 34 minutes" is a fact about the meeting, and adding up four people's
     * attendance would report it as two hours.
     */
    public static function heldMinutes($actualStart, $actualEnd): ?int
    {
        if (! $actualStart || ! $actualEnd) {
            return null;
        }

        // Machine instants, so no timezone is involved: the difference between
        // two UTC moments is the same number of minutes whoever is reading it.
        $from = Carbon::parse($actualStart);
        $to = Carbon::parse($actualEnd);

        return $to->lessThan($from) ? null : (int) round($from->diffInSeconds($to) / 60);
    }

    public static function label(?string $state): string
    {
        return self::LABELS[$state] ?? 'Unknown';
    }

    /**
     * Minutes until the start (negative once it has begun), or null with no date.
     * Drives "starts in 20 minutes" without every caller re-deriving the clock.
     */
    public static function minutesUntilStart($start, ?int $tenantId = null): ?int
    {
        $startsAt = BusinessTime::parse($start, $tenantId);

        return $startsAt ? (int) round(BusinessTime::now($tenantId)->diffInMinutes($startsAt, false)) : null;
    }
}
