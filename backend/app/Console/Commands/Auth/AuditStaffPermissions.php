<?php

namespace App\Console\Commands\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\StaffPermissionService;
use Illuminate\Console\Command;

/**
 * Who could reach HR yesterday, and who can reach it now the grid decides.
 *
 * Swapping a hardcoded role check for the permission grid is the kind of change
 * that looks fine in tests and locks the HR team out on a Monday morning. So
 * this runs BOTH rules over every real account and prints them side by side. A
 * row marked LOST is somebody who will ring you.
 *
 * The old rule is reproduced here rather than called, because the point is to
 * keep comparing after User::canManageHrQueue() is deleted — a comparison that
 * disappears when the thing it compares against does is no use for the next
 * module's cutover.
 */
class AuditStaffPermissions extends Command
{
    protected $signature = 'permissions:audit
        {--tenant= : Restrict to one tenant id}
        {--module=hr_attendance : The grid module the routes are gated on}
        {--capability=view_global : The capability the routes require}';

    protected $description = 'Compare the old hardcoded HR gate with the permission grid, per user';

    public function handle(StaffPermissionService $permissions): int
    {
        $module     = (string) $this->option('module');
        $capability = (string) $this->option('capability');

        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->pluck('id')
            : Tenant::pluck('id');

        $lost = 0;
        $gained = 0;
        $rows = [];

        foreach ($tenants as $tenantId) {
            $users = User::where('tenant_id', $tenantId)
                ->whereIn('role', ['admin', 'staff'])
                ->where('status', 'active')
                ->with('staffRole')
                ->get();

            foreach ($users as $user) {
                $before = $this->oldHrGate($user);
                $after  = $permissions->can($user, $capability, $module);

                $verdict = match (true) {
                    $before && ! $after => 'LOST',
                    ! $before && $after => 'gained',
                    default             => $before ? 'kept' : '—',
                };

                if ($verdict === 'LOST') {
                    $lost++;
                }
                if ($verdict === 'gained') {
                    $gained++;
                }

                $rows[] = [
                    $tenantId,
                    $user->email,
                    $user->role,
                    $user->internal_role ?: '—',
                    $user->staffRole?->slug ?: 'none',
                    $before ? 'yes' : 'no',
                    $after ? 'yes' : 'no',
                    $verdict,
                ];
            }
        }

        $this->table(
            ['Tenant', 'Email', 'Role', 'Internal', 'Staff role', 'Before', 'After', ''],
            $rows,
        );

        $this->newLine();
        $this->line("Gate: {$capability} on {$module}");

        if ($lost > 0) {
            $this->error("{$lost} account(s) LOSE access. Run `permissions:sync --commit` first, or grant the module to their role.");

            return self::FAILURE;
        }

        $this->info("Nobody loses access.".($gained > 0 ? " {$gained} account(s) gain it." : ''));

        return self::SUCCESS;
    }

    /**
     * User::canManageHrQueue() as it stood before the grid took over.
     *
     * Deliberately a copy. Calling the method would make this audit silently
     * useless the moment that method is deleted, which is precisely when the
     * comparison stops being available and nobody notices.
     */
    private function oldHrGate(User $user): bool
    {
        return $user->role === 'admin'
            || ($user->role === 'staff' && $user->internal_role === 'hr_executive')
            || in_array($user->internal_role, ['hr_recruiter', 'hr_executive'], true);
    }
}
