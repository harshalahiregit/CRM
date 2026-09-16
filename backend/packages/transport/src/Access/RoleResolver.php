<?php

namespace Transport\Access;

use App\Models\User;

/**
 * Which of Step 11's nine roles is this person?
 *
 * ── The gap this class refuses to paper over ──
 *
 * Step 11's permission sheet is keyed on CEO/Owner, Operations, Dispatcher,
 * Accounts, Approver, Driver, Customer, Supplier and Admin. Nowhere in the
 * package does anything say how a row in `users` becomes one of those nine. The
 * CRM has two different ideas of "role" and neither is the sheet's:
 *
 *   users.role           account type — admin, staff, client, purchase_vendor,
 *                        third_party_vendor, doctor
 *   users.internal_role  a staff_roles slug — hr_executive, team_lead, accounts,
 *                        hiring_manager, …
 *
 * Deciding that, say, internal_role `accounts` means the sheet's Accounts role
 * would be a business rule, and inventing a business rule is FORBID-001 (RED).
 * So this class does not decide it. The mapping lives in config/transport.php,
 * ships EMPTY, and until somebody fills it in with sign-off, everyone except an
 * administrator resolves to no transport role and is refused.
 *
 * That is deliberately inconvenient. A permission layer that guessed the mapping
 * would work on day one and be wrong in a way nobody could see; one that refuses
 * makes the missing decision impossible to ignore. BLK-08 in
 * docs/transport/TEAM-CONTRACTS.md.
 *
 * ── The one mapping that is not a guess ──
 *
 * `users.role === 'admin'` maps to the sheet's Admin column. The CRM already
 * treats that value as unrestricted (StaffPermissionService::BYPASS_ROLES) and
 * the sheet already has an Admin column granting everything, so the two agree
 * without anybody having to choose. Note the sheet still marks PERM-013 for
 * Admin with an asterisk, and the registry honours that.
 */
class RoleResolver
{
    /**
     * @return string|null one of PermissionRegistry::ROLES, or null when this
     *                     person has no transport role and may therefore do
     *                     nothing in transport
     */
    public function resolve(User $user): ?string
    {
        if ($user->role === 'admin') {
            return PermissionRegistry::ROLE_ADMIN;
        }

        // A configured mapping is consulted on the business role first, because
        // that is the specific statement, then on the account type, which is the
        // general one. Both are opt-in; an unmapped value stays unmapped.
        $map = config('transport.roles.map', []);

        foreach ([$user->internal_role, $user->role] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $mapped = $map[$candidate] ?? null;

            // A mapping onto a role the sheet does not define is a typo in
            // configuration, and must not become access. Ignore it and keep
            // looking, so a mistake narrows access rather than widening it.
            if (is_string($mapped) && in_array($mapped, PermissionRegistry::ROLES, true)) {
                return $mapped;
            }
        }

        return null;
    }

    /**
     * Everyone in this tenant whose role has not been mapped yet.
     *
     * Exists so the gap can be reported rather than discovered one locked-out
     * user at a time — `php artisan transport:roles` prints it before anybody
     * relies on the gate.
     *
     * @return array<string, int> unmapped role value => how many accounts hold it
     */
    public function unmapped(int $tenantId): array
    {
        $map    = config('transport.roles.map', []);
        $counts = [];

        User::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get(['role', 'internal_role'])
            ->each(function (User $user) use ($map, &$counts) {
                if ($user->role === 'admin') {
                    return;
                }

                $value = $user->internal_role ?: $user->role;

                if (! $value || isset($map[$value])) {
                    return;
                }

                $counts[$value] = ($counts[$value] ?? 0) + 1;
            });

        ksort($counts);

        return $counts;
    }
}
