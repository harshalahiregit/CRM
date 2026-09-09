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
     * The three groups that matter, with the counts a screen leads on.
     */
    public function report(int $tenantId): array
    {
        $employees = HrEmployee::where('tenant_id', $tenantId)
            ->whereIn('status', ['Active', 'On Probation'])
            ->get(['id', 'name', 'employee_code', 'department', 'designation', 'user_id', 'status']);

        $users = User::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get(['id', 'name', 'email', 'role', 'status']);

        $linkedUserIds = $employees->pluck('user_id')->filter()->unique();

        // An employment record with nobody able to sign in as them. Fine for a
        // site worker; a problem for anybody expected to use the app.
        $withoutLogin = $employees->whereNull('user_id')->values()->map(fn ($e) => [
            'employee_id'   => $e->id,
            'name'          => $e->name,
            'employee_code' => $e->employee_code,
            'department'    => $e->department,
            'designation'   => $e->designation,
        ])->all();

        // A login with no employment record. Legitimate for a super-admin or a
        // portal account — and the reason somebody is missing from payroll when
        // it is not.
        $withoutEmployee = $users->whereNotIn('id', $linkedUserIds->all())->values()->map(fn ($u) => [
            'user_id' => $u->id,
            'name'    => $u->name,
            'email'   => $u->email,
            'role'    => $u->role,
        ])->all();

        return [
            'summary' => [
                'employees'          => $employees->count(),
                'users'              => $users->count(),
                'linked'             => $linkedUserIds->count(),
                'without_login'      => count($withoutLogin),
                'without_employee'   => count($withoutEmployee),
            ],
            'without_login'    => $withoutLogin,
            'without_employee' => $withoutEmployee,
        ];
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
