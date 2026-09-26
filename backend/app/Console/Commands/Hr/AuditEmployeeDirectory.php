<?php

namespace App\Console\Commands\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\DirectoryReconciliationService;
use App\Services\Hr\EmployeeIdentityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What the employee and account directories actually look like, right now.
 *
 * READ-ONLY. It writes nothing, and it is meant to be safe to run against
 * production — the point is to find out what is there before deciding whether
 * anything should be changed, rather than assuming the data is clean and
 * discovering otherwise during a migration.
 *
 * The reconciliation panel shows a workspace its own problems. This shows an
 * operator every workspace at once, in a terminal, with counts: how much of what
 * kind, and whether the numbers are the handful you expect or the thousands that
 * mean a bad import happened.
 *
 * Nothing here is a repair. Where a fault has a remedy, the panel offers it under
 * HR → Employees; every one of them is a decision about which side is right, and
 * a command that guessed in bulk would be the worst possible way to make it.
 */
class AuditEmployeeDirectory extends Command
{
    protected $signature = 'hr:audit-directory
                            {--tenant= : Only this tenant id (default: every tenant)}
                            {--detail : List the individual records, not just counts}';

    protected $description = 'Read-only integrity report on employee/account identity and access';

    public function handle(): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->get()
            : Tenant::orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->error('No tenants matched.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('Employee ↔ account directory audit — READ ONLY, nothing is modified.');

        $grandTotal = 0;

        foreach ($tenants as $tenant) {
            $grandTotal += $this->auditTenant($tenant);
        }

        $this->line('');
        $this->line($grandTotal === 0
            ? '  No problems found.'
            : "  {$grandTotal} finding(s) across {$tenants->count()} tenant(s). Nothing has been changed.");
        $this->line('');

        return self::SUCCESS;
    }

    private function auditTenant(Tenant $tenant): int
    {
        $id = (int) $tenant->id;

        $employees = HrEmployee::where('tenant_id', $id)->count();
        $users     = User::where('tenant_id', $id)->count();

        $this->line('');
        $this->line("─── tenant #{$id} {$tenant->name} — {$employees} employees, {$users} accounts");

        $findings = 0;

        // The same detections the panel runs, so an operator and an admin cannot
        // be told different things about the same workspace.
        $report = app(DirectoryReconciliationService::class)->report($id);

        $bySeverity = collect($report['issues'])->groupBy('severity');

        foreach (['blocking', 'warning', 'info'] as $severity) {
            $group = $bySeverity->get($severity, collect());

            foreach ($group->groupBy('type') as $type => $rows) {
                $findings += $rows->count();
                $this->line(sprintf('  %-9s %-22s %d', strtoupper($severity), $type, $rows->count()));

                if ($this->option('detail')) {
                    foreach ($rows as $row) {
                        $this->line('              · '.$row['reason']);
                    }
                }
            }
        }

        // The two absences, which the panel reports separately from the issues.
        foreach ([
            'employees with no login'      => $report['summary']['without_login'],
            'internal logins with no employee record' => $report['summary']['without_employee'],
        ] as $label => $count) {
            if ($count > 0) {
                $this->line(sprintf('  %-9s %-22s %d', 'INFO', $label, $count));
            }
        }

        // Checks that are an operator's concern rather than an admin's, because
        // they are usually the signature of a bad import rather than of somebody
        // mis-editing one record.
        $findings += $this->extra($id);

        if ($findings === 0) {
            $this->line('  clean');
        }

        return $findings;
    }

    private function extra(int $tenantId): int
    {
        $found = 0;

        // Duplicate ACCOUNT addresses. users.email is globally unique, so this
        // should be structurally impossible — if it is not, the index is missing
        // and that is worth knowing before anything else here is trusted.
        $dupeUsers = DB::table('users')
            ->select('email', DB::raw('count(*) as c'))
            ->where('tenant_id', $tenantId)
            ->groupBy('email')
            ->having('c', '>', 1)
            ->get();

        foreach ($dupeUsers as $row) {
            $found++;
            $this->line("  BLOCKING  duplicate account email    {$row->email} ×{$row->c}");
        }

        // Two employee records with the same name. NOT necessarily a fault —
        // companies do employ two people called the same thing — so it is
        // reported as information, never as something to merge.
        $dupeNames = DB::table('hr_employees')
            ->select('name', DB::raw('count(*) as c'))
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->groupBy('name')
            ->having('c', '>', 1)
            ->get();

        foreach ($dupeNames as $row) {
            $this->line("  INFO      same employee name        {$row->name} ×{$row->c} (may be legitimate)");
        }

        // One account claimed by two employee records. The (tenant_id, user_id)
        // index should prevent it; if it appears, two people share a login.
        $sharedLogins = DB::table('hr_employees')
            ->select('user_id', DB::raw('count(*) as c'))
            ->where('tenant_id', $tenantId)
            ->whereNotNull('user_id')
            ->whereNull('deleted_at')
            ->groupBy('user_id')
            ->having('c', '>', 1)
            ->get();

        foreach ($sharedLogins as $row) {
            $found++;
            $this->line("  BLOCKING  one login, two employees   user #{$row->user_id} claimed ×{$row->c}");
        }

        // Employment says gone, the attendance app still says yes. The gate now
        // refuses it, but the flag is still set and still reads as granted on the
        // employee screen.
        $signIn = EmployeeIdentityService::EMPLOYMENT_STATUSES_THAT_MAY_SIGN_IN;

        $staleAppAccess = HrEmployee::where('tenant_id', $tenantId)
            ->where('app_login_enabled', true)
            ->whereNotIn('status', $signIn)
            ->count();

        if ($staleAppAccess > 0) {
            $found++;
            $this->line("  WARNING   app access left enabled    {$staleAppAccess} inactive employee(s) still flagged for the attendance app");
        }

        return $found;
    }
}
