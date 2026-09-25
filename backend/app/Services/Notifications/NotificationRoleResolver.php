<?php

namespace App\Services\Notifications;

use App\Models\User;

/**
 * Which role-targeted notifications a user is actually a recipient of.
 *
 * The engine addresses a notification either to a user id or to a role string —
 * 'hr', 'hr_manager', 'department_head', 'admin' — the four the escalation
 * ladder in config/hr_notifications.php produces. The role was stored and then
 * never read: NotificationRepository::visibleTo() showed EVERY role-targeted
 * row to anyone who could manage the HR queue. So a reminder escalated to a
 * department head landed in the inbox of every HR executive and nowhere near a
 * department head, and an escalation to 'admin' meant nothing at all.
 *
 * NO SECOND ROLE SYSTEM. Each ladder role maps onto a predicate the User model
 * already answers, so there is one vocabulary and these cannot drift from the
 * gates that enforce anything:
 *
 *   hr              → canManageHrQueue(), exactly the population that could
 *                     see these rows before. Unchanged on purpose.
 *   hr_manager      → the internal_role of that name, which canManageHrSetup()
 *                     and the leave gates already read.
 *   department_head → the internal_role of that name, which canApproveL1()
 *                     already reads.
 *   admin           → isAdmin().
 *
 * An UNRECOGNISED role resolves to administrators alone. Nobody would be a
 * silent hole — a notification nothing can display is worse than one shown too
 * narrowly — and everybody is the bug being fixed. Administrators are the
 * smallest population that is certain to exist.
 */
class NotificationRoleResolver
{
    public const HR              = 'hr';
    public const HR_MANAGER      = 'hr_manager';
    public const DEPARTMENT_HEAD = 'department_head';
    public const ADMIN           = 'admin';

    /** The roles the ladder and the sources actually produce. */
    public const KNOWN = [self::HR, self::HR_MANAGER, self::DEPARTMENT_HEAD, self::ADMIN];

    /**
     * The role names whose notifications this user should see.
     *
     * @return array<int, string> may be empty — then only their own rows show
     */
    public function rolesFor(User $user): array
    {
        // A client, vendor or doctor login is not staff and holds no HR role,
        // whatever its internal_role string happens to read. The same guard
        // canManageHrQueue() opens with.
        if (! $user->isStaffAccount()) {
            return [];
        }

        $roles = [];

        if ($user->canManageHrQueue()) {
            $roles[] = self::HR;
        }

        if ($user->isAdmin()) {
            $roles[] = self::ADMIN;
        }

        if ($user->internal_role === self::HR_MANAGER) {
            $roles[] = self::HR_MANAGER;
        }

        if ($user->internal_role === self::DEPARTMENT_HEAD) {
            $roles[] = self::DEPARTMENT_HEAD;
        }

        // The catch for a role nothing above names — an escalation ladder a
        // workspace edited, or one added later without a mapping here.
        if ($user->isAdmin()) {
            $roles[] = self::UNRECOGNISED;
        }

        return array_values(array_unique($roles));
    }

    /**
     * Marker for "a role this resolver does not know".
     *
     * Not a role anybody is addressed as — the repository turns it into
     * "…or a recipient_role outside the known list", so an unmapped role is
     * visible to administrators rather than to everyone or to no one.
     */
    public const UNRECOGNISED = '__unrecognised__';
}
