<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'external_company_id', 'name', 'email', 'password',
        'mail_from_name', 'mail_from_email',
        'role', 'internal_role', 'staff_role_id', 'department', 'status',
        'vendor_type', 'tpv_type', 'access_expires_at',
        'phone', 'company', 'designation', 'avatar', 'meta', 'emails_enabled',
        'last_login_at', 'last_login_ip',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'access_expires_at' => 'date',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'meta'              => 'array',
            'emails_enabled'    => 'boolean',
        ];
    }

    /* ── Relationships ──────────────────────── */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function externalCompany()
    {
        return $this->belongsTo(\App\Models\Hr\HrExternalCompany::class, 'external_company_id');
    }

    /* ── Role helpers ───────────────────────── */
    public function isAdmin():            bool { return $this->role === 'admin'; }
    public function isStaff():            bool { return $this->role === 'staff'; }

    /**
     * Whether this is an account that works INSIDE the CRM at all.
     *
     * Clients, vendors, third-party vendors, external companies and doctors are
     * rows in `users` exactly like staff are — same model, different `role` — and
     * they carry `internal_role` too. So any check written as
     * `in_array($this->internal_role, [...])` answers yes for a portal login
     * whose internal_role happens to hold a matching string, and there is nothing
     * theoretical about that: user 11 is a CLIENT carrying internal_role
     * 'director', which is one of the advance ladder's approval roles.
     *
     * EnsureUserHasRole and EnsureStaffPermission already make this distinction,
     * each with its own copy of the list and its own comment explaining why. This
     * is the same rule, named once, so a check on the model can make it too.
     *
     * An allow-list rather than a deny-list: a role added later is shut out until
     * somebody decides otherwise, which is the safe direction to be wrong in.
     */
    public function isStaffAccount(): bool { return in_array($this->role, ['admin', 'staff'], true); }
    /**
     * The assigned permission role, if any.
     *
     * Nullable on purpose and staying that way: everybody who existed before
     * roles were records has none, and keeps working on their own grid.
     */
    public function staffRole()
    {
        return $this->belongsTo(StaffRole::class, 'staff_role_id');
    }

    public function isHRExecutive():      bool { return $this->role === 'staff' && $this->internal_role === 'hr_executive'; }
    public function isHiringManager():    bool { return $this->role === 'staff' && $this->internal_role === 'hiring_manager'; }
    // No isVendor(): a Purchase Vendor is a PurchaseVendor, never a User. The helper
    // had no callers and only invited User-based checks on a separate identity.
    public function isThirdPartyVendor(): bool { return $this->role === 'third_party_vendor'; }
    public function isClient():           bool { return $this->role === 'client'; }
    public function isActive():           bool { return $this->status === 'active'; }
    public function isPending():          bool { return $this->status === 'pending'; }

    /* ── External company portal roles (role='company' + internal_role sub-role) ── */
    public function isCompany():             bool { return $this->role === 'company'; }
    public function isCompanyAdmin():        bool { return $this->isCompany() && $this->internal_role === \App\Support\Hr\CompanyRole::ADMIN; }
    public function isCompanyHr():           bool { return $this->isCompany() && $this->internal_role === \App\Support\Hr\CompanyRole::HR; }
    public function isCompanyHiringManager(): bool { return $this->isCompany() && $this->internal_role === \App\Support\Hr\CompanyRole::HIRING_MANAGER; }
    public function isCompanyInterviewer():  bool { return $this->isCompany() && $this->internal_role === \App\Support\Hr\CompanyRole::INTERVIEWER; }
    public function isCompanyViewer():       bool { return $this->isCompany() && $this->internal_role === \App\Support\Hr\CompanyRole::VIEWER; }
    /** Managers may act on requests/candidates/offers/documents/messages. */
    public function canManageCompany():      bool { return $this->isCompanyAdmin() || $this->isCompanyHr() || $this->isCompanyHiringManager(); }
    /** Interviewers may also act on interviews. */
    public function canManageCompanyInterviews(): bool { return $this->canManageCompany() || $this->isCompanyInterviewer(); }
    /** Role-based ability check across areas (dashboard/hiring_requests/…/team/settings). */
    public function companyCan(string $area, string $ability = 'manage'): bool
    {
        return $this->isCompany() && \App\Support\Hr\CompanyRole::can($this->internal_role, $area, $ability);
    }

    /* ── Recruitment approval authority ─────────────────────────────────────
     | L1 = Department Head level, L2 = Management level. Admin can do both.
     | Roles are matched against internal_role (staff) so the same helpers work
     | regardless of how the tenant labels its managers. Adjust the role sets
     | here to change who may approve — every check funnels through these two.
     |
     | The isStaffAccount() guard is the same one canManageHrQueue() carries, and
     | is needed for the same reason: internal_role is a free string that EVERY
     | account has, portal logins included, so `in_array($this->internal_role,
     | [...])` answered yes for a client or an external company whose column
     | happened to read 'department_head'. Approving a headcount request is
     | company authority; a customer contact does not hold it by coincidence.
     */
    public function canApproveL1(): bool
    {
        if (! $this->isStaffAccount()) {
            return false;
        }

        return $this->isAdmin()
            || in_array($this->internal_role, ['department_head', 'hiring_manager'], true)
            || $this->grantedAuthority('hr_manpower_l1');
    }

    public function canApproveL2(): bool
    {
        if (! $this->isStaffAccount()) {
            return false;
        }

        return $this->isAdmin()
            || in_array($this->internal_role, ['project_manager', 'senior_executive'], true)
            || $this->grantedAuthority('hr_manpower_l2');
    }

    /**
     * May act on the HR queue (convert to JD, publish, close).
     *
     * The account-type guard comes FIRST, and does the work the role-string
     * clause cannot: isAdmin() and isHRExecutive() both pin `role` themselves,
     * but `in_array($this->internal_role, [...])` never did, so a client or
     * vendor login whose internal_role read 'hr_executive' satisfied the HR gate
     * on 117 call sites. It also covers the grid clause below — a portal account
     * granted hr_employees is still refused, because it never reaches it.
     *
     * The last clause is the one that makes a configurable role mean something.
     * Until it existed, creating "Senior HR Executive" in HR Settings and ticking
     * every box granted nothing: HR authority was three hardcoded strings, and a
     * developer had to add a fourth before the role could do its job. Now the
     * permission grid is an ALTERNATIVE way in.
     *
     * Strictly widening, and deliberately so. Every existing clause is untouched
     * and evaluated first, so no account that could act yesterday can be refused
     * today — `php artisan permissions:audit` reports that per user and must keep
     * saying "Nobody loses access".
     *
     * One coarse module rather than per-area ones. hr_employees:view_global is
     * the whole of HR for now; splitting the 117 call sites into hr_payroll /
     * hr_leave / hr_exit is a separate pass, once there are real custom roles to
     * test it against. Naming the other modules early (they exist in
     * StaffPermission::MODULES) is what lets that happen without a second
     * vocabulary change.
     */
    public function canManageHrQueue(): bool
    {
        if (! $this->isStaffAccount()) {
            return false;
        }

        return $this->isAdmin()
            || $this->isHRExecutive()
            || in_array($this->internal_role, ['hr_recruiter', 'hr_executive'], true)
            || app(\App\Services\Auth\StaffPermissionService::class)->can(
                $this,
                \App\Support\Hr\StaffPermission::VIEW_GLOBAL,
                'hr_employees',
            );
    }

    /**
     * May manage the Employee Onboarding module (HR-driven in Sprint 1).
     * Future ESS will add an 'employee' scope resolved via HrEmployee.user_id;
     * this helper stays the HR gate.
     *
     * Guarded on account type like every other HR helper — it is the single gate
     * on 26 onboarding routes, and its role-string clause never pinned `role`.
     *
     * Note 'hr_manager' is in that list and is not a seeded staff_roles slug, so
     * it can only ever match an internal_role somebody typed by hand. Left as it
     * is: removing it would narrow access, which is not what this pass is for.
     */
    public function canManageOnboarding(): bool
    {
        if (! $this->isStaffAccount()) {
            return false;
        }

        return $this->isAdmin()
            || $this->isHRExecutive()
            || in_array($this->internal_role, ['hr_recruiter', 'hr_executive', 'hr_manager'], true)
            || $this->grantedAuthority('hr_onboarding');
    }

    /**
     * May use the AI Job Description generator. Restricted to HR Recruiter,
     * HR Manager and Super Admin (per the AI JD sprint spec).
     *
     * Same guard, same reason. This one bills a third-party AI call, so an
     * unauthorised caller costs money as well as reaching something they should
     * not.
     */
    public function canGenerateAiJd(): bool
    {
        if (! $this->isStaffAccount()) {
            return false;
        }

        return $this->isAdmin()
            || in_array($this->internal_role, ['hr_recruiter', 'hr_manager'], true)
            || $this->grantedAuthority('hr_ai_jd');
    }

    /**
     * Does the permission grid give this person a narrow HR authority?
     *
     * The four helpers above each used to be a fixed list of internal_role
     * strings, which meant a role created in HR Settings could never hold them
     * however many boxes an admin ticked — a developer had to add the slug to
     * PHP first, which is the whole thing the configurable-role work exists to
     * remove.
     *
     * Each is now an ADDITIONAL way in, never a replacement: every original
     * clause is evaluated first and unchanged, so no account that could act
     * yesterday is refused today.
     *
     * view_global on a module this narrow is the same statement as "may act" —
     * the same reading canManageHrQueue() already takes of hr_employees. These
     * modules exist for one authority each and have no other capabilities.
     */
    private function grantedAuthority(string $module): bool
    {
        return app(\App\Services\Auth\StaffPermissionService::class)
            ->can($this, \App\Support\Hr\StaffPermission::VIEW_GLOBAL, $module);
    }

    /* ── Scopes ─────────────────────────────── */
    /**
     * A doctor login's practising identity (licence, council, signature). Only
     * ever set for role=doctor; null everywhere else, which is what stops a
     * non-doctor from signing a certificate.
     */
    public function doctorProfile()
    {
        return $this->hasOne(\App\Models\Medical\MedicalDoctorProfile::class, 'user_id');
    }

    public function isDoctor(): bool { return $this->role === 'doctor'; }

    public function scopeActive($query)        { return $query->where('status', 'active'); }
    public function scopePending($query)       { return $query->where('status', 'pending'); }
    public function scopeOfTenant($q, $tid)    { return $q->where('tenant_id', $tid); }
}
