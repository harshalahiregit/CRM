<?php

namespace App\Console\Commands\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Services\Hr\EmployeeIdentityService;
use Illuminate\Console\Command;

/**
 * Match the employees and logins that already exist, and report what it finds.
 *
 * `hr_employees.user_id` has never been written, so on any live database every
 * employee is unlinked and every admin has a login with no employee record. This
 * pairs them where the evidence is unambiguous.
 *
 * REPORT ONLY unless --commit is passed. A backfill that links identities is not
 * reversible in any meaningful sense — once people start clocking in against a
 * link, unpicking a wrong one means deciding whose attendance a day belonged to.
 * So the default is to print what it WOULD do and let a human read it.
 *
 * Matching is on (tenant_id, email) and never on email alone. users.email is
 * globally unique, so an email-only match returns exactly one account which may
 * belong to a different tenant — linking that would bind one workspace's employee
 * to another workspace's login.
 */
class ReconcileEmployeeLogins extends Command
{
    protected $signature = 'hr:reconcile-logins
        {--tenant= : Restrict to one tenant id}
        {--commit : Actually write the links. Without this nothing is changed}';

    protected $description = 'Pair existing employees with existing logins by email, within a tenant';

    public function handle(EmployeeIdentityService $identity): int
    {
        $commit = (bool) $this->option('commit');

        $employees = HrEmployee::query()
            ->whereNull('user_id')
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
            ->orderBy('tenant_id')->orderBy('employee_code')
            ->get();

        if ($employees->isEmpty()) {
            $this->info('Every employee already has a login linked. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line($commit
            ? "Linking {$employees->count()} unlinked employees…"
            : "DRY RUN — {$employees->count()} unlinked employees. Nothing will be written.");
        $this->newLine();

        $rows = [];
        $counts = ['link' => 0, 'no-email' => 0, 'no-account' => 0, 'conflict' => 0, 'linked' => 0];

        foreach ($employees as $employee) {
            $email = trim((string) ($employee->official_email ?: $employee->email));

            if ($email === '') {
                $counts['no-email']++;
                $rows[] = [$employee->tenant_id, $employee->employee_code, $employee->name, '—', 'no email'];
                continue;
            }

            // Tenant-scoped deliberately — see the class docblock.
            $match = User::where('tenant_id', $employee->tenant_id)->where('email', $email)->first();

            if (! $match) {
                // Is the address taken elsewhere? Worth surfacing, because it means
                // provisioning a login for this person will fail until it is resolved.
                $elsewhere = User::where('email', $email)->exists();
                $counts[$elsewhere ? 'conflict' : 'no-account']++;
                $rows[] = [
                    $employee->tenant_id, $employee->employee_code, $employee->name, $email,
                    $elsewhere ? 'EMAIL BELONGS TO ANOTHER WORKSPACE' : 'no account yet',
                ];
                continue;
            }

            $claimed = HrEmployee::where('tenant_id', $employee->tenant_id)
                ->where('user_id', $match->id)->first();

            if ($claimed) {
                $counts['conflict']++;
                $rows[] = [
                    $employee->tenant_id, $employee->employee_code, $employee->name, $email,
                    "ALREADY {$claimed->employee_code}",
                ];
                continue;
            }

            if (! $commit) {
                $counts['link']++;
                $rows[] = [$employee->tenant_id, $employee->employee_code, $employee->name, $email, "would link → user {$match->id}"];
                continue;
            }

            try {
                $identity->provision($employee);
                $counts['linked']++;
                $rows[] = [$employee->tenant_id, $employee->employee_code, $employee->name, $email, "linked → user {$match->id}"];
            } catch (BusinessException $e) {
                $counts['conflict']++;
                $rows[] = [$employee->tenant_id, $employee->employee_code, $employee->name, $email, 'REFUSED: ' . $e->getMessage()];
            }
        }

        $this->table(['Tenant', 'Code', 'Name', 'Email', 'Outcome'], $rows);
        $this->newLine();

        foreach ($counts as $k => $n) {
            if ($n > 0) {
                $this->line('  ' . str_pad($k, 12) . $n);
            }
        }

        if (! $commit && $counts['link'] > 0) {
            $this->newLine();
            $this->info("Re-run with --commit to write {$counts['link']} link(s).");
        }

        // A conflict is not a crash, but it does need a person. Exit non-zero so a
        // scripted run does not report success over rows nobody looked at.
        return $counts['conflict'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
