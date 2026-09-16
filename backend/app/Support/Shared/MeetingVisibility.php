<?php

namespace App\Support\Shared;

use App\Models\User;

/**
 * Who may see which meetings.
 *
 * Until now the engine was tenant-wide with no per-user filter, and that was
 * safe by accident: ten admin/staff logins, and every meeting was vendor
 * governance that all of them were entitled to read. Opening Meetings to every
 * internal role removes that accident — an HR one-to-one and a doctor's medical
 * review now live in the same table as a vendor kickoff.
 *
 * The rule, decided with the user:
 *   admin  → every meeting in the tenant, plus the reports.
 *   anyone else → the meetings they organise or were invited to.
 * Nothing else differs. Same screens, same features; only the data narrows.
 *
 * ── Why invitees are matched on e-mail as well as user_id ───────────────
 * A participant picked from the directory carries user_id. One typed in by
 * address does not — and that person is invited, is e-mailed, gets the bell,
 * and would open the module to find their own meeting missing. Matching both
 * closes the gap that would otherwise be reported as "the meeting disappeared".
 *
 * ── Why this is not a global scope ─────────────────────────────────────
 * The engine reads its own meetings for carry-forward actions, prior-meeting
 * lookups and reminder sweeps, none of which belong to a viewer. A global scope
 * would silently narrow those to whoever happened to be logged in. Visibility is
 * therefore applied at the handful of places a PERSON reads a list, and nowhere
 * else — see the call sites in KickoffMeetingRepository and MeetingRegisterService.
 */
final class MeetingVisibility
{
    /**
     * Roles that may use the Meetings module.
     *
     * Every internal role. Externals (client, vendor, third_party_vendor) are
     * deliberately absent: they keep the read-only governance view in their own
     * portal, which shows the meetings they are in and nothing else. They remain
     * fully selectable AS participants — being invited to a meeting and being
     * able to schedule one are different things.
     */
    public const INTERNAL_ROLES = ['admin', 'staff', 'manager', 'hr', 'doctor'];

    /** The admin sees the whole tenant. That is the only difference. */
    public static function seesEverything(?User $user): bool
    {
        return (bool) $user?->isAdmin();
    }

    /**
     * Narrow a meetings query to what this viewer may read.
     *
     * $column lets callers whose query is rooted elsewhere (the decision, issue
     * and action registers all select FROM their own table and join meetings)
     * point the subquery at the right meeting id.
     */
    public static function apply($query, ?User $user)
    {
        if (self::seesEverything($user) || ! $user) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('created_by', $user->id)
                ->orWhereHas('attendees', fn ($a) => self::matchPerson($a, $user));
        });
    }

    /** Is this attendee row this person? By identity first, then by address. */
    public static function matchPerson($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id);
            if ($user->email) {
                $q->orWhereRaw('LOWER(email) = ?', [mb_strtolower($user->email)]);
            }
        });
    }

    /** The ids this viewer may read — for registers that start from another table. */
    public static function meetingIdsFor(int $tenantId, ?User $user): ?array
    {
        if (self::seesEverything($user) || ! $user) {
            return null;   // null = no restriction, distinct from an empty list
        }

        return self::apply(
            \App\Models\Shared\KickoffMeeting::forTenant($tenantId),
            $user
        )->pluck('id')->all();
    }
}
