<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Models\StaffRole;
use App\Models\User;
use App\Support\Hr\StaffPermission;
use App\Support\Hr\StaffRoleTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Roles: creating them, assigning them, and working out what somebody may do.
 *
 * A workspace's roles are seeded from StaffRoleTemplate the first time they are
 * asked for, rather than by a migration touching every tenant. A tenant created
 * next month gets them the same way, and a migration cannot forget.
 *
 * Assignment writes the role's slug to users.internal_role as well. That column
 * is matched by hardcoded checks all over the codebase — canManageHrQueue,
 * AgencyContext, AdvanceTierService — and keeping it in sync is what lets roles
 * become records WITHOUT rewriting any of them. It is denormalised on purpose;
 * staff_role_id is the source of truth, internal_role is its shadow.
 */
class StaffRoleService
{
    /**
     * Every role for a tenant, seeding the system ones if this is the first ask.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,StaffRole>
     */
    public function forTenant(int $tenantId)
    {
        $this->ensureSeeded($tenantId);

        return StaffRole::where('tenant_id', $tenantId)
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create the standard roles for a tenant that has none.
     *
     * firstOrCreate per slug rather than a bulk insert, so running this against
     * a tenant that already has some — or has renamed one — adds what is missing
     * and touches nothing else.
     */
    public function ensureSeeded(int $tenantId): int
    {
        $existing = StaffRole::where('tenant_id', $tenantId)->pluck('slug')->all();
        $added    = 0;

        foreach (StaffRoleTemplate::DEFINITIONS as $slug => $def) {
            if (in_array($slug, $existing, true)) {
                continue;
            }

            StaffRole::create([
                'tenant_id'   => $tenantId,
                'name'        => $def['label'],
                'slug'        => $slug,
                'permissions' => StaffPermission::sanitise($def['permissions']),
                'is_system'   => true,
            ]);

            $added++;
        }

        return $added;
    }

    public function create(int $tenantId, array $data): StaffRole
    {
        $slug = StaffRole::slugify($data['name']);

        if (StaffRole::where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            throw new BusinessException('A role with that name already exists.', 422);
        }

        return StaffRole::create([
            'tenant_id'   => $tenantId,
            'name'        => trim($data['name']),
            'slug'        => $slug,
            'description' => $data['description'] ?? null,
            'permissions' => StaffPermission::sanitise($data['permissions'] ?? []),
            'is_system'   => false,
        ]);
    }

    /**
     * Rename a role or change what it grants.
     *
     * The slug never moves. It is written into users.internal_role and matched
     * by name in a dozen places, so changing it would silently revoke access
     * from everybody holding the role — the failure would look like a
     * permissions bug weeks later, not like a rename.
     */
    public function update(StaffRole $role, array $data): StaffRole
    {
        $role->update(array_filter([
            'name'        => isset($data['name']) ? trim($data['name']) : null,
            'description' => $data['description'] ?? null,
            'permissions' => isset($data['permissions'])
                ? StaffPermission::sanitise($data['permissions'])
                : null,
        ], fn ($v) => $v !== null));

        return $role->fresh();
    }

    public function delete(StaffRole $role): void
    {
        if ($role->is_system) {
            throw new BusinessException('Standard roles can be edited but not deleted.', 422);
        }

        $inUse = User::where('staff_role_id', $role->id)->count();

        if ($inUse > 0) {
            // Deleting out from under people would leave them on whatever their
            // grid happened to hold, which is not a decision anybody made.
            throw new BusinessException(
                $inUse === 1
                    ? 'One staff member still has this role. Move them to another role first.'
                    : "{$inUse} staff members still have this role. Move them to another role first.",
                422
            );
        }

        $role->delete();
    }

    /**
     * Put somebody on a role.
     *
     * Writes the slug to internal_role in the same transaction, so the two can
     * never disagree — a user whose role says Accounts but whose internal_role
     * says something else would be approving advances or not depending on which
     * check ran.
     */
    public function assign(User $user, ?StaffRole $role): User
    {
        return DB::transaction(function () use ($user, $role) {
            $user->forceFill([
                'staff_role_id' => $role?->id,
                // Clearing the role leaves internal_role alone rather than
                // blanking it: it was somebody's job title before roles existed,
                // and losing it would be a silent demotion.
                'internal_role' => $role?->slug ?? $user->internal_role,
            ])->save();

            return $user->fresh();
        });
    }

    /**
     * What this user may actually do.
     *
     * The per-user grid WINS where it is set. That is deliberate and it is how
     * every existing account keeps working: they all have a grid and no role, so
     * nothing about them changes. A role fills in the modules the grid does not
     * mention, which is also what makes "give them the Accounts role plus one
     * extra module" expressible.
     */
    public function effectiveGrants(User $user): array
    {
        $own = StaffPermission::sanitise($user->meta['permissions'] ?? []);

        if (! $user->staff_role_id) {
            return $own;
        }

        $role = $user->relationLoaded('staffRole') ? $user->staffRole : $user->staffRole()->first();

        if (! $role) {
            return $own;
        }

        // Module-level override, not capability-level union: a grid that names a
        // module means somebody decided that module's answer for this person, and
        // merging the role's capabilities back in would quietly re-grant what
        // they had just taken away.
        return array_replace($role->grants(), $own);
    }
}
