<?php

namespace App\Console\Commands\Auth;

use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\StaffRoleService;
use App\Support\Hr\StaffPermission;
use App\Support\Hr\StaffRoleTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Make the permission grid true for people who already exist, before it is enforced.
 *
 * The grid has been stored and never read, so access has been decided by
 * hardcoded role checks. The moment a route starts consulting the grid instead,
 * anybody whose grid is empty loses that route — and almost every grid is empty,
 * because nothing ever needed to fill one in.
 *
 * So this runs FIRST and does two things:
 *
 *   1. Adds modules a seeded role does not mention yet. Roles were seeded before
 *      `self` and `hr_attendance` existed, so no role grants either and every HR
 *      screen would 403 on the day it starts checking. Modules the role already
 *      names are left exactly as they are — an admin who edited a role must not
 *      have that edit reverted by an upgrade.
 *
 *   2. Assigns a role to staff who have none, matched on `internal_role`, which
 *      is the same slug. That is where their access comes from today; without it
 *      their grid resolves to empty and they lose everything at once.
 *
 * REPORT ONLY unless --commit is passed. Granting permissions is not something to
 * discover after the fact, and the report is short enough to read.
 */
class SyncStaffPermissions extends Command
{
    protected $signature = 'permissions:sync
        {--tenant= : Restrict to one tenant id}
        {--commit : Actually write. Without this nothing is changed}';

    protected $description = 'Fill in role permissions and assign roles to staff who have none, before the grid is enforced';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->pluck('id')
            : Tenant::pluck('id');

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenantId) {
            $this->line("── tenant {$tenantId}");
            $this->seedMissingRoles((int) $tenantId, $commit);
            $this->topUpRoles((int) $tenantId, $commit);
            $this->assignRoles((int) $tenantId, $commit);
        }

        $this->newLine();
        $this->line($commit
            ? 'Written. Run `permissions:audit` to see who can reach what.'
            : 'Nothing was written. Re-run with --commit to apply.');

        return self::SUCCESS;
    }

    private function seedMissingRoles(int $tenantId, bool $commit): void
    {
        $missing = array_diff(
            array_keys(StaffRoleTemplate::DEFINITIONS),
            StaffRole::where('tenant_id', $tenantId)->pluck('slug')->all(),
        );

        if ($missing === []) {
            return;
        }

        $this->line('   roles to create: '.implode(', ', $missing));

        if ($commit) {
            app(StaffRoleService::class)->ensureSeeded($tenantId);
        }
    }

    /** Add modules a role does not mention. Never touches one it does. */
    private function topUpRoles(int $tenantId, bool $commit): void
    {
        foreach (StaffRole::where('tenant_id', $tenantId)->get() as $role) {
            $template = StaffRoleTemplate::DEFINITIONS[$role->slug]['permissions'] ?? null;

            if (! $template) {
                continue;   // A role the workspace invented. Not ours to change.
            }

            $current = $role->grants();
            $added   = [];

            foreach (StaffPermission::sanitise($template) as $module => $caps) {
                if (! array_key_exists($module, $current)) {
                    $current[$module] = $caps;
                    $added[] = $module;
                }
            }

            if ($added === []) {
                continue;
            }

            $this->line("   {$role->slug}: + ".implode(', ', $added));

            if ($commit) {
                $role->update(['permissions' => StaffPermission::sanitise($current)]);
            }
        }
    }

    /**
     * Give staff with no role the one whose slug matches their internal_role.
     *
     * Admins are skipped: they bypass the grid entirely, and pinning them to a
     * role would be the first step towards an admin who cannot do something.
     */
    private function assignRoles(int $tenantId, bool $commit): void
    {
        $roles = StaffRole::where('tenant_id', $tenantId)->get()->keyBy('slug');

        $unassigned = User::where('tenant_id', $tenantId)
            ->whereNull('staff_role_id')
            ->where('role', 'staff')
            ->get(['id', 'name', 'email', 'internal_role']);

        if ($unassigned->isEmpty()) {
            $this->line('   every staff member already holds a role');

            return;
        }

        foreach ($unassigned as $user) {
            // Somebody with no internal_role at all is a plain staff member; the
            // employee role is what they can do today and nothing more.
            $slug = $user->internal_role ?: 'employee';
            $role = $roles[$slug] ?? $roles['employee'] ?? null;

            if (! $role) {
                $this->warn("   {$user->email}: no role matches '{$slug}' and there is no employee role — SKIPPED");

                continue;
            }

            $this->line("   {$user->email} → {$role->slug}");

            if ($commit) {
                DB::table('users')->where('id', $user->id)->update(['staff_role_id' => $role->id]);
            }
        }
    }
}
