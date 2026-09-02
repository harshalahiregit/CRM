<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Hr\StaffPermission;

/**
 * The one place that answers "may this person do X to Y".
 *
 * The permission grid has been saved to users.meta.permissions since Staff
 * Management shipped and has never been read. There are no Gates and no Policies
 * in this codebase; authorization today is 91 hardcoded canManageHrQueue() calls
 * plus role-string comparisons, none of which consult the grid.
 *
 * This service does not change any of that on its own. Nothing calls it yet by
 * design: switching 23 modules onto a permission set that has never been enforced
 * would lock people out of work they do daily, on the strength of boxes nobody
 * knew were load-bearing. It is introduced first, so modules can be moved across
 * one at a time and each move reviewed.
 *
 * The old CRM's staff_can($capability, $feature, $staff_id) is the shape being
 * reproduced, including its two escape hatches: an admin flag that bypasses the
 * grid, and view_own as a narrower alternative to view_global rather than a
 * separate concept.
 */
class StaffPermissionService
{
    /**
     * Roles that bypass the grid entirely.
     *
     * The old CRM has `tblstaff.admin` for exactly this, and it is worth copying:
     * an administrator who must tick 115 boxes to do their job is one
     * mis-configuration away from being locked out of the screen that fixes it.
     */
    private const BYPASS_ROLES = ['admin'];

    /** The grid as stored, sanitised. Never trusts what is in the column. */
    /**
     * What this user may do, role included.
     *
     * Resolved through StaffRoleService so there is ONE answer to this question.
     * A user with no role — which is everybody who existed before roles became
     * records — resolves to their own grid exactly as before, so nothing about
     * an existing account changes.
     */
    public function grantsFor(User $user): array
    {
        return app(StaffRoleService::class)->effectiveGrants($user);
    }

    public function bypasses(User $user): bool
    {
        return in_array($user->role, self::BYPASS_ROLES, true);
    }

    /**
     * May this user perform $capability on $module?
     *
     * Unknown modules and capabilities return false. A caller asking about
     * something that does not exist is a bug in the caller, and answering "yes"
     * to a question nobody defined is how a permission system quietly stops
     * meaning anything.
     */
    public function can(User $user, string $capability, string $module): bool
    {
        if (! StaffPermission::isModule($module) || ! StaffPermission::isCapability($capability)) {
            return false;
        }

        if ($this->bypasses($user)) {
            return true;
        }

        $granted = $this->grantsFor($user)[$module] ?? [];

        if (in_array($capability, $granted, true)) {
            return true;
        }

        // view_global implies view_own: someone trusted with the whole company's
        // records is necessarily trusted with their own. The reverse is not true,
        // which is the entire point of keeping them separate.
        if ($capability === StaffPermission::VIEW_OWN) {
            return in_array(StaffPermission::VIEW_GLOBAL, $granted, true);
        }

        return false;
    }

    /**
     * How wide is this person's view of a module?
     *
     * Returns 'global', 'own', or null for no access. This is what a report or a
     * list should branch on: 'global' sees the company, 'own' sees their own
     * department or their own records, null sees nothing.
     */
    public function scope(User $user, string $module): ?string
    {
        if ($this->can($user, StaffPermission::VIEW_GLOBAL, $module)) {
            return 'global';
        }

        if ($this->can($user, StaffPermission::VIEW_OWN, $module)) {
            return 'own';
        }

        return null;
    }
}
