<?php

namespace App\Support\Shared;

use Illuminate\Database\Eloquent\Model;

/**
 * A kick-off happens once.
 *
 * It is the meeting held when a vendor first starts working with the team, so
 * a second one is not a meeting — it is somebody picking the wrong type from a
 * list of two dozen. Everything else in the catalogue recurs by design: weekly
 * coordination, progress review, technical, financial, HSE. Only this one is a
 * beginning, and a subject can only begin once.
 *
 * ── Why a cancelled kick-off does not count ─────────────────────────────
 * A kick-off that was called off never happened. Letting it block the real one
 * for ever would mean the only way to hold a kick-off after a false start is to
 * delete the record of the false start — which is exactly the history worth
 * keeping. Cancelled is excluded; every other state counts, including Draft, so
 * two half-written kick-offs cannot both be published.
 *
 * ── Why this is a support class and not a database constraint ───────────
 * The rule is "one per subject, ignoring cancelled ones", which no unique index
 * expresses. It also has to produce a message naming the meeting already in the
 * way, and a 422 that says "a kick-off already exists" without saying WHICH is
 * a dead end for whoever hit it.
 */
final class KickoffOnce
{
    /** The one type in the catalogue that may not repeat. */
    public const TYPE = 'kickoff';

    /**
     * Statuses that do NOT count as an existing kick-off.
     *
     * Only cancelled. A completed kick-off obviously counts, and so does a
     * scheduled or draft one — the point is to stop a second being made, not to
     * wait until the first has happened.
     */
    public const IGNORED_STATUSES = ['Cancelled'];

    /** Is this the type that may only happen once? */
    public static function applies(?string $meetingType): bool
    {
        return $meetingType === self::TYPE;
    }

    /**
     * What to tell somebody who just tried to create a second one.
     *
     * Names the meeting in the way and when it is, because "a kick-off already
     * exists" leaves them with nowhere to go. Says what to pick instead, since
     * the usual cause is the default type being left alone on a meeting that
     * was meant to be a progress review.
     */
    public static function message(Model $existing): string
    {
        $when = $existing->scheduled_at
            ? $existing->scheduled_at->format('d M Y')
            : 'a date still to be set';

        $reference = $existing->meeting_no ?: ($existing->reference ?: '#'.$existing->getKey());

        return "A kick-off meeting already exists for this vendor — {$reference} on {$when}. "
            .'A kick-off is held once, when they first start working with the team. '
            .'Pick another meeting type, or cancel that one first.';
    }
}
