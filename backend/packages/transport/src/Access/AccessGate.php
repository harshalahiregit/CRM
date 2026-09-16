<?php

namespace Transport\Access;

use App\Models\User;

/**
 * The one place that answers "may this person do X in transport, and to how much".
 *
 * Deny by default, in all four of the ways a permission check can be asked a
 * question it has no answer to:
 *
 *   the permission is not in the registry   → refused, and says which hole
 *   the person has no transport role        → refused, and says the mapping is missing
 *   the cell says N                         → refused
 *   the cell is asterisked                  → refused until the footnote exists
 *
 * None of those is an error condition to be smoothed over. STOS-SEC §237 puts it
 * plainly: when authorisation cannot be established, deny. The alternative — a
 * gate that returns true when it does not understand the question — is how a
 * control stops meaning anything while still appearing to be there.
 *
 * ── Scope, not a boolean ──
 *
 * `scope()` is the real answer and `allows()` is a convenience over it. A caller
 * that only asks allows() and then queries every row has implemented PERM-001
 * backwards: the sheet gives a driver `Own` and a supplier `Assigned` precisely
 * so that they see less, not so that they see everything after passing a check.
 */
class AccessGate
{
    public function __construct(private RoleResolver $roles)
    {
    }

    /**
     * How much of $domain.$action is this person allowed?
     *
     * @return string|null PermissionRegistry::FULL|OWN|ASSIGNED, or null for refused
     */
    public function scope(User $user, string $domain, string $action): ?string
    {
        if (! PermissionRegistry::defines($domain, $action)) {
            return null;
        }

        $role = $this->roles->resolve($user);

        if ($role === null) {
            return null;
        }

        $grant = PermissionRegistry::MATRIX[PermissionRegistry::key($domain, $action)]['grants'][$role]
            ?? PermissionRegistry::NONE;

        return match ($grant) {
            PermissionRegistry::FULL,
            PermissionRegistry::OWN,
            PermissionRegistry::ASSIGNED => $grant,
            default                      => null,
        };
    }

    public function allows(User $user, string $domain, string $action): bool
    {
        return $this->scope($user, $domain, $action) !== null;
    }

    /**
     * Why was that refused?
     *
     * Every branch here is a different problem with a different owner, and a
     * single "forbidden" would hide which. BRWM §70 asks that a blocked action
     * tell the user what is blocked, why, and who can resolve it; this is the
     * backend half of that.
     */
    public function denialReason(User $user, string $domain, string $action): string
    {
        $key = PermissionRegistry::key($domain, $action);

        if (! PermissionRegistry::defines($domain, $action)) {
            $why = PermissionRegistry::whyUnregistered($domain, $action);

            return $why
                ? "No permission exists for {$key}. {$why} Permissions are deny-by-default, so this stays refused until the registry defines it."
                : "No permission exists for {$key}, and it is not one of the known gaps. Check the action name against Step 11.";
        }

        if ($this->roles->resolve($user) === null) {
            return 'This account has no transport role. The mapping from CRM roles to the nine roles in Step 11 has not been agreed yet, so nobody but an administrator can be granted anything.';
        }

        $id = PermissionRegistry::idFor($domain, $action);

        return "{$id} does not grant {$key} to this role.";
    }
}
