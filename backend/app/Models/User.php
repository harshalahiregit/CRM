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
        'role', 'internal_role', 'department', 'status',
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
    public function isHRExecutive():      bool { return $this->role === 'staff' && $this->internal_role === 'hr_executive'; }
    public function isHiringManager():    bool { return $this->role === 'staff' && $this->internal_role === 'hiring_manager'; }
    public function isVendor():           bool { return $this->role === 'vendor'; }
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
     */
    public function canApproveL1(): bool
    {
        return $this->isAdmin()
            || in_array($this->internal_role, ['department_head', 'hiring_manager'], true);
    }

    public function canApproveL2(): bool
    {
        return $this->isAdmin()
            || in_array($this->internal_role, ['project_manager', 'senior_executive'], true);
    }

    /** May act on the HR queue (convert to JD, publish, close). */
    public function canManageHrQueue(): bool
    {
        return $this->isAdmin()
            || $this->isHRExecutive()
            || in_array($this->internal_role, ['hr_recruiter', 'hr_executive'], true);
    }

    /**
     * May manage the Employee Onboarding module (HR-driven in Sprint 1).
     * Future ESS will add an 'employee' scope resolved via HrEmployee.user_id;
     * this helper stays the HR gate.
     */
    public function canManageOnboarding(): bool
    {
        return $this->isAdmin()
            || $this->isHRExecutive()
            || in_array($this->internal_role, ['hr_recruiter', 'hr_executive', 'hr_manager'], true);
    }

    /**
     * May use the AI Job Description generator. Restricted to HR Recruiter,
     * HR Manager and Super Admin (per the AI JD sprint spec).
     */
    public function canGenerateAiJd(): bool
    {
        return $this->isAdmin()
            || in_array($this->internal_role, ['hr_recruiter', 'hr_manager'], true);
    }

    /* ── Scopes ─────────────────────────────── */
    public function scopeActive($query)        { return $query->where('status', 'active'); }
    public function scopePending($query)       { return $query->where('status', 'pending'); }
    public function scopeOfTenant($q, $tid)    { return $q->where('tenant_id', $tid); }
}
