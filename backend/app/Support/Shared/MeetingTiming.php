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
 * job to run and no column to fall out of date. The five states:
 *
 *   draft      not published — the vendor cannot see it at all
 *   upcoming   published, has not started
 *   live       started, not yet ended
 *   expired    ended while still open — nobody completed or cancelled it
 *   closed     Completed or Cancelled; the clock stopped mattering
 *
 * Every comparison runs on the tenant's business clock, because that is what
 * the stored wall clock means ({@see BusinessTime}).
 */
final class MeetingTiming
{
    public const DRAFT    = 'draft';
    public const UPCOMING = 'upcoming';
    public const LIVE     = 'live';
    public const EXPIRED  = 'expired';
    public const CLOSED   = 'closed';

    public const LABELS = [
        self::DRAFT    => 'Draft',
        self::UPCOMING => 'Upcoming',
        self::LIVE     => 'In progress',
        self::EXPIRED  => 'Expired',
        self::CLOSED   => 'Closed',
    ];

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
     */
    public static function state($start, $end, ?int $durationMinutes, bool $isDraft, bool $isClosed, ?int $tenantId = null): string
    {
        if ($isDraft) {
            return self::DRAFT;
        }
        if ($isClosed) {
            return self::CLOSED;
        }

        $startsAt = BusinessTime::parse($start, $tenantId);
        if (! $startsAt) {
            // Published with no date on it. It cannot have passed, so it is
            // still ahead of everyone rather than expired.
            return self::UPCOMING;
        }

        $now = BusinessTime::now($tenantId);
        if ($now->lessThan($startsAt)) {
            return self::UPCOMING;
        }

        $endsAt = self::endsAt($start, $end, $durationMinutes, $tenantId);

        return $endsAt && $now->greaterThanOrEqualTo($endsAt) ? self::EXPIRED : self::LIVE;
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
