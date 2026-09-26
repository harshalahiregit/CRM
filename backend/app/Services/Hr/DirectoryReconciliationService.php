<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\User;

/**
 * Where the staff directory and the employee directory disagree.
 *
 * The instruction was one directory, not two: "एम्प्लई या स्टाफ जो रखना है एक
 * रखो इसको... अनवांटेड चीजें हमें क्यों करनी, कन्फ्यूजन होगा ना — पता चला किसी
 * को ऐड करना था, एम्प्लई ने ऐड कर दिया, स्टाफ ने नहीं".
 *
 * ── Why they are NOT merged into one table ──
 *
 * They are not duplicates. `users` is a LOGIN ACCOUNT — who can sign in and what
 * they may reach — and `hr_employees` is an EMPLOYMENT RECORD — who is employed
 * and what they are paid. The two overlap heavily and are already linked by
 * `hr_employees.user_id`, but neither contains the other: a vendor portal login
 * and a super-admin are users who are not employees, and a worker with no system
 * access is an employee who is not a user.
 *
 * Collapsing them into one table would also break code that is not HR's: Tasks,
 * Helpdesk tickets, ticket threads and support settings all resolve their
 * assignable-people lists from the staff directory, and every one of them would
 * have to change at the same time. That is a large, cross-module change that
 * cannot be verified from inside this module.
 *
 * ── What was actually wrong, and what this fixes ──
 *
 * The complaint was not that two tables exist. It was that somebody gets added
 * in one place and is missing from the other, and nobody finds out until they
 * are left off a payroll run or cannot log in. That is a RECONCILIATION problem,
 * and it is solved by making the gap visible rather than by a migration: this
 * reports who is on one side and not the other, so the two lists can be kept in
 * step deliberately instead of by memory.
 */
class DirectoryReconciliationService
{
    /**
     * Roles that SHOULD have an employment record.
     *
     * Everyone else — a customer contact, a third-party contractor, a purchase
     * supplier, a doctor, a company portal login — holds a login precisely
     * because they are NOT an employee, so their absence from HR is the correct
     * state rather than a gap to close.
     */
    private const INTERNAL_ROLES = ['staff', 'admin'];

    /**
     * The three groups that matter, with the counts a screen leads on.
     */
    public function report(int $tenantId): array
    {
        $employees = HrEmployee::where('tenant_id', $tenantId)
            ->whereIn('status', ['Active', 'On Probation'])
            ->get(['id', 'name', 'employee_code', 'department', 'designation', 'user_id', 'status',
                   'email', 'official_email']);

        // INTERNAL roles only.
        //
        // This panel exists to find people who fell between the two directories.
        // It used to read every active login, so a customer contact and a
        // third-party contractor were reported as "missing an employee record" —
        // which they are, correctly and permanently: a customer is not on your
        // payroll and never will be. Seven were listed where three were real,
        // and a count that is mostly false alarms is one nobody reads.
        //
        // The same list the backfill command uses (hr:reconcile-logins, which
        // filters to staff and admin), so the screen and the fix cannot disagree
        // about who is supposed to have a record.
        $users = User::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('role', self::INTERNAL_ROLES)
            ->get(['id', 'name', 'email', 'role', 'status']);

        $linkedUserIds = $employees->pluck('user_id')->filter()->unique();

        $unlinkedUsers = $users->whereNotIn('id', $linkedUserIds->all())->values();

        // A login with no employment record. Legitimate for a super-admin or a
        // portal account — and the reason somebody is missing from payroll when
        // it is not.
        $withoutEmployee = $unlinkedUsers->map(fn ($u) => [
            'user_id' => $u->id,
            'name'    => $u->name,
            'email'   => $u->email,
            'role'    => $u->role,
        ])->all();

        // An employment record with nobody able to sign in as them. Fine for a
        // site worker; a problem for anybody expected to use the app.
        //
        // Each row carries a suggestion when — and only when — an unlinked login
        // in this tenant holds exactly the same email address. Exact, and unique:
        // two accounts sharing an address, or a near miss on a name, produce no
        // suggestion at all. A wrong link here hands one person's payslips and
        // attendance to another, so anything short of certainty is left for a
        // human to decide, and even the certain case is only ever OFFERED — the
        // admin still presses the button.
        $byEmail = $unlinkedUsers->groupBy(fn ($u) => strtolower(trim((string) $u->email)));

        $withoutLogin = $employees->whereNull('user_id')->values()->map(function ($e) use ($byEmail) {
            $email = strtolower(trim((string) ($e->official_email ?: $e->email)));
            $match = $email !== '' ? $byEmail->get($email) : null;
            $unique = $match && $match->count() === 1 ? $match->first() : null;

            return [
                'employee_id'    => $e->id,
                'name'           => $e->name,
                'employee_code'  => $e->employee_code,
                'department'     => $e->department,
                'designation'    => $e->designation,
                'email'          => $e->official_email ?: $e->email,
                'suggested_user' => $unique ? [
                    'user_id' => $unique->id,
                    'name'    => $unique->name,
                    'email'   => $unique->email,
                ] : null,
            ];
        })->all();

        $dismissed = $this->dismissed($tenantId);
        $issues = array_values(array_filter(
            $this->linkageIssues($tenantId),
            fn ($i) => ! in_array($i['key'], $dismissed, true),
        ));

        return [
            'summary' => [
                'employees'          => $employees->count(),
                'users'              => $users->count(),
                'linked'             => $linkedUserIds->count(),
                'without_login'      => count($withoutLogin),
                'without_employee'   => count($withoutEmployee),
                'issues'             => count($issues),
                'blocking'           => count(array_filter($issues, fn ($i) => $i['severity'] === 'blocking')),
            ],
            'without_login'    => $withoutLogin,
            'without_employee' => $withoutEmployee,
            'issues'           => $issues,
        ];
    }

    /**
     * The problems that are NOT "one side is missing".
     *
     * The panel used to detect exactly two things, and both of them were absences.
     * Everything below is a pair that EXISTS and is wrong, which is the harder
     * class to find by eye and the one that actually hurt: a deactivated employee
     * with a live login was invisible here, because the link was intact.
     *
     * Severity is about consequence, not tidiness:
     *
     *   blocking  — someone can reach the system who should not, or a record is
     *               structurally broken. Fix today.
     *   warning   — the two sides disagree about a fact. Somebody will act on the
     *               wrong one.
     *   info      — worth a look, legitimate in some workspaces.
     *
     * Each issue carries a stable `key` so it can be dismissed and stay dismissed,
     * and an `action` naming what the panel may offer. No issue is ever fixed
     * automatically — every one of these needs a human to say which side is right.
     */
    private function linkageIssues(int $tenantId): array
    {
        $issues = [];

        $employees = HrEmployee::where('tenant_id', $tenantId)
            ->whereNotNull('user_id')
            ->get(['id', 'user_id', 'name', 'employee_code', 'email', 'official_email',
                   'phone', 'department', 'designation', 'status', 'tenant_id']);

        $users = User::whereIn('id', $employees->pluck('user_id')->filter()->unique())
            ->get(['id', 'name', 'email', 'phone', 'department', 'designation', 'role', 'status', 'tenant_id'])
            ->keyBy('id');

        $maySignIn = EmployeeIdentityService::EMPLOYMENT_STATUSES_THAT_MAY_SIGN_IN;

        foreach ($employees as $e) {
            $u = $users->get($e->user_id);

            // 7 — the link points at a login that is not there any more.
            if (! $u) {
                $issues[] = $this->issue('broken_link', 'blocking', $e,
                    null,
                    "Linked to account #{$e->user_id}, which no longer exists.",
                    'clear_link');
                continue;
            }

            // 8 — and the one that is a tenant boundary, not an inconvenience.
            if ((int) $u->tenant_id !== $tenantId) {
                $issues[] = $this->issue('cross_tenant', 'blocking', $e, $u,
                    'Linked to an account in another workspace.',
                    'clear_link');
                continue;
            }

            // 3 — employment says gone, the account says come in. The gate refuses
            // the login now, but the account is still live and still counted as
            // active everywhere else, so it is reported rather than left implied.
            if (! in_array((string) $e->status, $maySignIn, true) && $u->status === 'active') {
                $issues[] = $this->issue('access_mismatch', 'blocking', $e, $u,
                    "Employment is {$e->status} but the account is still active.",
                    'deactivate_account');
            }

            // 4 — an employee whose login is typed as a portal account. Legitimate
            // history, dangerous silence: it used to hide them from Staff
            // Management entirely.
            if (! in_array($u->role, ['staff', 'admin'], true)) {
                $issues[] = $this->issue('permission_mismatch', 'warning', $e, $u,
                    "Account role is “{$u->role}”, which is not a staff or admin account.",
                    null);
            }

            // 5 — the two sides describing the same person differently.
            $differences = $this->identityDifferences($e, $u);

            if ($differences !== []) {
                $issues[] = $this->issue('identity_mismatch', 'warning', $e, $u,
                    'Account details differ from the employee record: '.implode(', ', array_keys($differences)).'.',
                    'sync_identity', ['differences' => $differences]);
            }
        }

        // 6 — two accounts on one address. users.email is globally unique, so this
        // can only be two EMPLOYEE records claiming the same one; whichever is
        // wrong, a login cannot be provisioned for both.
        $dupes = HrEmployee::where('tenant_id', $tenantId)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get(['id', 'name', 'employee_code', 'email', 'status'])
            ->groupBy(fn ($e) => strtolower(trim((string) $e->email)))
            ->filter(fn ($group) => $group->count() > 1);

        foreach ($dupes as $email => $group) {
            $issues[] = [
                'key'      => 'duplicate_email:'.$email,
                'type'     => 'duplicate_email',
                'severity' => 'warning',
                'employee' => null,
                'user'     => null,
                'reason'   => $group->count().' employee records share the address '.$email.': '
                              .$group->map(fn ($e) => $e->name.' ('.$e->employee_code.')')->implode(', ').'.',
                'action'   => null,
            ];
        }

        return $issues;
    }

    /**
     * Where a linked pair disagree about the same fact.
     *
     * Blank on the account side is NOT a difference. Most of these columns were
     * never populated — the account was created before the HR module existed, or
     * by a path that never set a department — and reporting every one of them as a
     * conflict would bury the handful that are real. Empty means "not answered",
     * and only two different answers are a mismatch.
     */
    private function identityDifferences(HrEmployee $e, User $u): array
    {
        $employeeEmail = trim((string) ($e->official_email ?: $e->email));

        $pairs = [
            'name'        => [$e->name, $u->name],
            'email'       => [$employeeEmail, $u->email],
            'phone'       => [$e->phone, $u->phone],
            'department'  => [$e->department, $u->department],
            'designation' => [$e->designation, $u->designation],
        ];

        $out = [];

        foreach ($pairs as $field => [$employeeValue, $accountValue]) {
            $employeeValue = trim((string) $employeeValue);
            $accountValue  = trim((string) $accountValue);

            if ($employeeValue === '' || $accountValue === '') {
                continue;
            }

            if (strcasecmp($employeeValue, $accountValue) !== 0) {
                $out[$field] = ['employee' => $employeeValue, 'account' => $accountValue];
            }
        }

        return $out;
    }

    private function issue(string $type, string $severity, HrEmployee $e, ?User $u, string $reason, ?string $action, array $extra = []): array
    {
        return array_merge([
            'key'      => $type.':'.$e->id,
            'type'     => $type,
            'severity' => $severity,
            'employee' => [
                'employee_id'   => $e->id,
                'name'          => $e->name,
                'employee_code' => $e->employee_code,
                'department'    => $e->department,
                'designation'   => $e->designation,
                'status'        => $e->status,
                'email'         => $e->official_email ?: $e->email,
                'phone'         => $e->phone,
            ],
            'user'     => $u ? [
                'user_id'     => $u->id,
                'name'        => $u->name,
                'email'       => $u->email,
                'phone'       => $u->phone,
                'department'  => $u->department,
                'designation' => $u->designation,
                'role'        => $u->role,
                'status'      => $u->status,
            ] : null,
            'reason'   => $reason,
            'action'   => $action,
        ], $extra);
    }

    /**
     * Issues an admin has looked at and decided are fine.
     *
     * See the hr_directory_dismissals migration for why this is a table and not a
     * tenant setting — chiefly that dismissing an identity problem is a decision
     * somebody made, and it should say who.
     *
     * Degrades to "nothing is dismissed" when the table is not there, so a
     * workspace that has not run the migration still gets a working panel rather
     * than a 500.
     */
    public function dismissed(int $tenantId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hr_directory_dismissals')) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('hr_directory_dismissals')
            ->where('tenant_id', $tenantId)
            ->pluck('issue_key')
            ->all();
    }

    public function dismiss(int $tenantId, string $key, ?int $actorId = null): array
    {
        \Illuminate\Support\Facades\DB::table('hr_directory_dismissals')->updateOrInsert(
            ['tenant_id' => $tenantId, 'issue_key' => $key],
            ['dismissed_by' => $actorId, 'updated_at' => now(), 'created_at' => now()],
        );

        return $this->dismissed($tenantId);
    }

    public function restore(int $tenantId, string $key): array
    {
        \Illuminate\Support\Facades\DB::table('hr_directory_dismissals')
            ->where('tenant_id', $tenantId)
            ->where('issue_key', $key)
            ->delete();

        return $this->dismissed($tenantId);
    }

    /**
     * Point an existing employment record at an existing login.
     *
     * Deliberately links rather than creates. Creating an employee from a user
     * would have to invent a joining date, a department and a designation, and
     * an invented joining date is a wrong figure in every service calculation
     * and gratuity accrual from that day on.
     */
    public function link(HrEmployee $employee, User $user, int $tenantId): array
    {
        if ((int) $user->tenant_id !== $tenantId || (int) $employee->tenant_id !== $tenantId) {
            throw new \App\Exceptions\BusinessException('That record belongs to another workspace.', 404);
        }

        $taken = HrEmployee::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('id', '!=', $employee->id)
            ->first();

        if ($taken) {
            throw new \App\Exceptions\BusinessException(
                'That login is already linked to '.$taken->name.'. One account cannot belong to two employees.'
            );
        }

        $employee->update(['user_id' => $user->id]);

        return ['employee_id' => $employee->id, 'user_id' => $user->id];
    }
}
