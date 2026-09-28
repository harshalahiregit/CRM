<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The one place a login and an employee record become the same person.
 *
 * `hr_employees.user_id` has existed since the onboarding tables were added and
 * has never had a single writer — not EmployeeService::create, not onboarding
 * confirmation, not the SangoeTrack importer, and StoreEmployeeRequest has no
 * rule for it. The column was designed and never wired, which is exactly why
 * "even the admin is an employee" does not work today: an admin has a login and
 * no employee row, and nothing joins the two.
 *
 * Everything downstream assumes one row per human — self check-in, per-employee
 * reports, notification addressing, and the mobile app knowing who is holding the
 * phone. So this is deliberately the only path that creates that link.
 *
 * WHAT IT REFUSES TO DO, and why:
 *
 * Never links across tenants. `users.email` is globally unique, so one address is
 * exactly one login belonging to exactly one tenant. A lookup by email alone —
 * the shape VendorEmployeeService::grantAccess uses — therefore happily returns
 * another tenant's account and binds this tenant's employee to it. With one
 * tenant today that is invisible; the day a second exists it is a data breach
 * that reads as a bug. Every lookup here carries the tenant.
 *
 * Never hijacks an account that is not a person's own staff login. An email
 * belonging to a client, vendor or TPV portal account is refused rather than
 * quietly repurposed.
 *
 * Never silently relinks. If the employee already has a login, or the login is
 * already somebody else's employee record, it says so instead of overwriting —
 * the (tenant_id, user_id) unique index would throw anyway, and a clear refusal
 * beats a constraint violation surfacing as a 500.
 */
class EmployeeIdentityService
{
    /** Portal identities that must never be turned into an employee login. */
    private const PORTAL_ROLES = ['client', 'vendor', 'third_party_vendor', 'company'];

    /**
     * Give this employee a login, creating one if needed. Idempotent.
     *
     * @param  string  $role  the CRM role the new login gets when one is created
     * @return array{user: User, created: bool, temporary_password: ?string}
     */
    public function provision(HrEmployee $employee, string $role = 'staff', ?User $actor = null): array
    {
        // Already linked — hand back what exists rather than making a second one.
        if ($employee->user_id) {
            $existing = User::where('tenant_id', $employee->tenant_id)->find($employee->user_id);

            if ($existing) {
                return ['user' => $existing, 'created' => false, 'temporary_password' => null];
            }

            // The link points at a login that is gone, or at another tenant's.
            // Refuse: repairing it is a decision, not something to do implicitly.
            throw new BusinessException(
                "This employee is linked to a login that no longer exists in this workspace. Clear the link before creating a new one.",
                422
            );
        }

        $email = trim((string) ($employee->official_email ?: $employee->email));

        if ($email === '') {
            throw new BusinessException('This employee needs an email address before a login can be created.', 422);
        }

        return DB::transaction(function () use ($employee, $email, $role, $actor) {
            $existing = User::where('email', $email)->first();

            if ($existing) {
                return $this->linkExisting($employee, $existing, $email);
            }

            $password = Str::password(14);

            $user = User::create([
                'name'      => $employee->name ?: $email,
                'email'     => $email,
                'password'  => Hash::make($password),
                'role'      => $role,
                'tenant_id' => $employee->tenant_id,
                'status'    => 'active',
            ]);

            $employee->update(['user_id' => $user->id]);

            Log::channel('hr')->info('Employee login provisioned', [
                'employee_id' => $employee->id,
                'user_id'     => $user->id,
                'tenant_id'   => $employee->tenant_id,
                'by'          => $actor?->id,
            ]);

            // Returned once, never stored. The caller decides how it reaches the
            // person; it must not be logged.
            return ['user' => $user, 'created' => true, 'temporary_password' => $password];
        });
    }

    /**
     * Attach an employee to a login that already exists, or explain why not.
     */
    private function linkExisting(HrEmployee $employee, User $existing, string $email): array
    {
        if ((int) $existing->tenant_id !== (int) $employee->tenant_id) {
            Log::channel('hr')->warning('Refused a cross-tenant employee link', [
                'employee_id'      => $employee->id,
                'employee_tenant'  => $employee->tenant_id,
                'user_id'          => $existing->id,
                'user_tenant'      => $existing->tenant_id,
            ]);

            throw new BusinessException(
                "The email {$email} already belongs to an account in another workspace.",
                422
            );
        }

        if (in_array($existing->role, self::PORTAL_ROLES, true)) {
            throw new BusinessException(
                "The email {$email} belongs to a portal account ({$existing->role}) and cannot be used as an employee login.",
                422
            );
        }

        $claimedBy = HrEmployee::where('tenant_id', $employee->tenant_id)
            ->where('user_id', $existing->id)
            ->where('id', '!=', $employee->id)
            ->first();

        if ($claimedBy) {
            throw new BusinessException(
                "That login already belongs to {$claimedBy->name} ({$claimedBy->employee_code}).",
                422
            );
        }

        $employee->update(['user_id' => $existing->id]);

        return ['user' => $existing, 'created' => false, 'temporary_password' => null];
    }

    /**
     * The reverse of provision(): give a LOGIN an employee record.
     *
     * Staff Management creates the person, and the old CRM's answer to why is
     * that tblstaff simply IS the person — payroll, contracts and timesheets all
     * hang off staff_id and the HR module creates nobody. Sangoe keeps two tables
     * because hr_employees carries probation, shift and salary, which a login has
     * no business holding; but they must behave as one record, which means the
     * two rows are written together or not at all.
     *
     * Without this you can produce a login that no HR screen can see and that
     * cannot clock in — which is exactly the state every admin account is in
     * today.
     *
     * Idempotent, and it prefers linking to creating: an employee already in this
     * tenant with the same address is that person, not a second one.
     *
     * @param  array{department?:string,designation?:string,phone?:string,joining_date?:string}  $details
     */
    public function provisionEmployeeFor(User $user, array $details = [], ?User $actor = null): HrEmployee
    {
        if ($existing = $this->employeeFor($user)) {
            return $existing;
        }

        $email = trim((string) $user->email);

        // An employee record already exists for this person — link it rather than
        // creating a second one. Matching is tenant-scoped for the same reason
        // every lookup here is.
        $match = HrEmployee::where('tenant_id', $user->tenant_id)
            ->whereNull('user_id')
            ->where(fn ($q) => $q->where('email', $email)->orWhere('official_email', $email))
            ->first();

        if ($match) {
            $match->update(['user_id' => $user->id]);

            Log::channel('hr')->info('Linked an existing employee to a login', [
                'employee_id' => $match->id, 'user_id' => $user->id, 'by' => $actor?->id,
            ]);

            return $match->fresh();
        }

        $employee = HrEmployee::create([
            'tenant_id'     => $user->tenant_id,
            'user_id'       => $user->id,
            'employee_code' => HrEmployee::nextEmployeeCode((int) $user->tenant_id),
            'name'          => $user->name,
            'email'         => $email,
            'phone'         => $details['phone'] ?? $user->phone,
            'department'    => $details['department'] ?? ($user->department ?: 'Unassigned'),
            'designation'   => $details['designation'] ?? ($user->designation ?: 'Unassigned'),
            // The day they were given a login is the best available answer, and a
            // guess HR can correct beats a NULL that blocks the record from saving.
            'joining_date'  => $details['joining_date'] ?? now()->toDateString(),
            'status'        => 'Active',
        ]);

        Log::channel('hr')->info('Employee record created for a login', [
            'employee_id' => $employee->id, 'user_id' => $user->id,
            'code' => $employee->employee_code, 'by' => $actor?->id,
        ]);

        return $employee;
    }

    /**
     * Employment statuses that still leave a person able to sign in.
     *
     * An allowlist, for the same reason AuthService::assertUserCanLogin uses one:
     * a status added later is refused until somebody deliberately admits it,
     * rather than silently granting access the day it appears in a dropdown.
     *
     * 'On Leave' is in. Somebody on maternity leave still has to read a payslip,
     * apply for more leave and see the holiday calendar — being away is not the
     * same as being gone, and the leave module would be unusable to exactly the
     * people who need it. 'Inactive' is the one that means gone.
     */
    public const EMPLOYMENT_STATUSES_THAT_MAY_SIGN_IN = ['Active', 'On Leave'];

    /**
     * Why employment blocks this login, or null when it does not.
     *
     * The gap this closes: hr_employees.status was a label on an HR screen and
     * nothing else. Deactivating an employee left `users.status` at 'active' and
     * `app_login_enabled` at true, so the person kept a working CRM login and a
     * working attendance app — verified by logging in as one. HR believed they
     * had revoked access; nothing had.
     *
     * Deliberately narrow:
     *
     *   No linked employee → not our question. Portal accounts, the system admin
     *   of a workspace that does not use HR, an API integration login: none of
     *   them have an hr_employees row, and inventing one to judge them by would
     *   break logins that are working correctly today.
     *
     *   The founding admin of a tenant is exempt. Staff Management already
     *   refuses to demote or delete them (see foundingAdminId — the old CRM's
     *   cant_remove_main_admin) precisely so a workspace can never be left with
     *   nobody in charge. Without the same exemption here, one careless edit on
     *   the HR Employees screen — a screen that says nothing about access —
     *   would lock every administrator out of the tenant with no way back in.
     *   The refusal is logged either way, so the exemption is visible when it
     *   is used rather than being a silent hole.
     */
    public function employmentRefusalReason(User $user): ?string
    {
        $employee = $this->employeeFor($user);

        if ($employee === null) {
            return null;
        }

        if (in_array((string) $employee->status, self::EMPLOYMENT_STATUSES_THAT_MAY_SIGN_IN, true)) {
            return null;
        }

        if ($this->isFoundingAdmin($user)) {
            Log::channel('auth')->warning('Founding admin signed in with an inactive employee record', [
                'user_id'         => $user->id,
                'employee_id'     => $employee->id,
                'employee_status' => $employee->status,
                'tenant_id'       => $user->tenant_id,
            ]);

            return null;
        }

        return 'Your employment record is not active. Contact HR.';
    }

    /**
     * The earliest admin in the tenant — the account the workspace was created
     * with. Mirrors StaffManagementController::foundingAdminId(); per-tenant
     * rather than a global row id, so a second workspace has its own.
     */
    private function isFoundingAdmin(User $user): bool
    {
        if ($user->role !== 'admin') {
            return false;
        }

        return (int) $user->id === (int) User::where('tenant_id', $user->tenant_id)
            ->where('role', 'admin')
            ->min('id');
    }

    /**
     * Push the employee's identity fields onto the login attached to them.
     *
     * The ONE synchronisation point, called from both write paths (the HR
     * employee form and Staff Management), so there is no second answer to keep
     * in step with this one.
     *
     * Direction is deliberate and one-way: hr_employees owns who the person is,
     * `users` owns how they get in. Editing an employee's phone number in HR and
     * finding Staff Management still showing the old one — or worse, blank — was
     * the whole complaint. Writing back the other way as well would make two
     * owners and a race, which is what produced the divergence in the first
     * place.
     *
     * `users.email` moves with it because it IS the credential: leaving a login
     * on an address HR has retired means somebody signs in as an identity the
     * employee record no longer claims. It is skipped, loudly, when another
     * account already holds that address — the column is globally unique and a
     * collision is a decision for a human, not something to resolve implicitly.
     *
     * Nothing else on the account is touched: not the password, not the status,
     * not the permission role, not meta. Those belong to the account.
     */
    public function syncLoginFromEmployee(HrEmployee $employee, ?User $actor = null): ?User
    {
        if (! $employee->user_id) {
            return null;
        }

        $user = User::where('tenant_id', $employee->tenant_id)->find($employee->user_id);

        if (! $user) {
            return null;
        }

        $changes = [];

        foreach ([
            'name'        => $employee->name,
            'phone'       => $employee->phone,
            'department'  => $employee->department,
            'designation' => $employee->designation,
        ] as $column => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if ($value !== null && $value !== '' && (string) $user->{$column} !== (string) $value) {
                $changes[$column] = $value;
            }
        }

        $email = trim((string) ($employee->official_email ?: $employee->email));

        if ($email !== '' && strcasecmp($email, (string) $user->email) !== 0) {
            $takenBy = User::where('email', $email)->where('id', '!=', $user->id)->value('id');

            if ($takenBy) {
                Log::channel('hr')->warning('Employee email not pushed to the login — address already in use', [
                    'employee_id' => $employee->id,
                    'user_id'     => $user->id,
                    'taken_by'    => $takenBy,
                ]);
            } else {
                $changes['email'] = $email;
            }
        }

        if ($changes === []) {
            return $user;
        }

        $user->forceFill($changes)->save();

        Log::channel('hr')->info('Login updated from its employee record', [
            'employee_id' => $employee->id,
            'user_id'     => $user->id,
            'fields'      => array_keys($changes),
            'by'          => $actor?->id,
        ]);

        return $user;
    }

    /**
     * May this login sign in to the attendance app?
     *
     * Four things must all hold, and each is a different question with a
     * different owner:
     *
     *   the CRM account is active   — Staff Management, via users.status
     *   they are an employee        — there is an hr_employees row linked to them
     *   they are still employed     — hr_employees.status
     *   HR has granted app access   — hr_employees.app_login_enabled
     *
     * Deliberately separate from the CRM login gate. An office admin has a
     * perfectly good CRM account and no business clocking in from a phone;
     * equally, revoking app access must not lock somebody out of the CRM.
     *
     * WHEN THE CRM SERVES THE APP (Phase 4), a refusal here must be returned as
     * HTTP 403 with {"status": 0, "message": ...} — never 401. The published app
     * treats 401 as a dead token: it wipes local storage, including the cached
     * clock-in state, and bounces to the login screen with no message at all. A
     * person refused app access would simply see a blank screen and lose an open
     * shift. 403 shows them the reason.
     */
    public function mayUseApp(User $user): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        $employee = $this->employeeFor($user);

        if ($employee === null || $employee->app_login_enabled !== true) {
            return false;
        }

        // The same gate the CRM login applies. Leaving it out here would mean a
        // deactivated employee was refused at the CRM sign-in page and still
        // clocking in from the phone in their pocket — the app is the half that
        // actually records attendance, so it is the half that matters most.
        return in_array((string) $employee->status, self::EMPLOYMENT_STATUSES_THAT_MAY_SIGN_IN, true);
    }

    /**
     * Why app access was refused, in words meant for the person holding the
     * phone. Null when it is allowed.
     */
    public function appRefusalReason(User $user): ?string
    {
        if ($user->status !== 'active') {
            return 'Your account is not active. Contact your administrator.';
        }

        $employee = $this->employeeFor($user);

        if ($employee === null) {
            return 'You do not have an employee record. Contact HR.';
        }

        // Named before the generic message below, so somebody who was
        // deactivated is told that rather than being sent to ask HR for an app
        // permission they already have.
        if (! in_array((string) $employee->status, self::EMPLOYMENT_STATUSES_THAT_MAY_SIGN_IN, true)) {
            return 'Your employment record is not active. Contact HR.';
        }

        if (! $this->mayUseApp($user)) {
            return 'You have not been given access to the attendance app. Contact HR.';
        }

        return null;
    }

    /**
     * The employee record for a login, within that login's own tenant.
     *
     * The read half of the link: given the person holding a session, which
     * employee are they? Self check-in and every "my own" screen needs this.
     */
    public function employeeFor(User $user): ?HrEmployee
    {
        return HrEmployee::where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->first();
    }
}
