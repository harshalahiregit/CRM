<?php

namespace App\Services\Transport;

use App\Models\User;
use App\Support\Transport\TransportPermission;

/**
 * Answers "may this person do X in Transport, and over which records"
 * (SNG-TRN-028).
 *
 * One class, so the answer cannot drift between the middleware, a service and a
 * frontend capability payload. StaffPermissionService's docblock records what
 * happens otherwise — 91 hardcoded checks that ignore the grid entirely.
 *
 * Two deliberate properties:
 *
 *   DENY BY DEFAULT. Step 11: "Every action must resolve to an explicit
 *   role/permission; deny by default." An unmapped identity, an unknown
 *   permission and an unknown role all return false.
 *
 *   NO ADMIN BYPASS. StaffPermissionService grants admins a blanket bypass so a
 *   misconfigured grid cannot lock them out of the screen that fixes it. That
 *   reasoning does not carry here: this matrix is code, not tenant
 *   configuration, so there is nothing an admin could misconfigure and nothing
 *   to rescue them from. Admin is simply a row in the matrix like any other.
 */
class TransportPermissionService
{
    /**
     * Map a Sangoe identity onto a STOS matrix role, or null if it maps to none.
     *
     * Portal identities (PurchaseVendor, ClientContact) are not Users and never
     * resolve — the same reasoning EnsureUserHasRole records: a portal model
     * carries its own `role` column, and a free-text value there must never
     * satisfy a staff gate.
     */
    public function stosRole(mixed $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        $map = TransportPermission::ROLE_MAP;

        // internal_role is only meaningful for staff, and is checked first so a
        // specific transport role beats the generic staff mapping.
        if ($user->role === 'staff' && $user->internal_role) {
            $key = 'internal:'.$user->internal_role;
            if (isset($map[$key])) {
                return $map[$key];
            }
        }

        return $map['role:'.$user->role] ?? null;
    }

    /** May this user perform $permission at all (in any scope)? */
    public function can(mixed $user, string $permission): bool
    {
        return $this->scope($user, $permission) !== null;
    }

    /**
     * The scope this user holds for $permission — all | own | assigned — or null
     * when they hold none. Callers that list records use this to narrow the
     * query; "own" and "assigned" narrowing arrives with the tickets that own
     * the assignment concept (SNG-TRN-009), so nothing here pretends to
     * implement it yet.
     */
    public function scope(mixed $user, string $permission): ?string
    {
        return TransportPermission::scopeFor($permission, $this->stosRole($user));
    }

    /**
     * Every permission this user holds, as key => scope.
     *
     * Used to tell a client what to render. A UI that hides what the backend
     * would refuse is honest; a UI that shows a button the API rejects is the
     * "fake success" pattern the team conventions forbid.
     *
     * @return array<string,string>
     */
    public function grantsFor(mixed $user): array
    {
        $role = $this->stosRole($user);

        if ($role === null) {
            return [];
        }

        $grants = [];
        foreach (TransportPermission::all() as $permission) {
            $scope = TransportPermission::scopeFor($permission, $role);
            if ($scope !== null) {
                $grants[$permission] = $scope;
            }
        }

        return $grants;
    }
}
