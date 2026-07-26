<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\Hr\HrCandidateSubmission;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrExternalCompany;
use App\Models\Hr\HrHiringRequest;
use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrOffer;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Hr\CompanyRole;
use App\Support\Hr\HiringRequestStatus as Status;
use App\Support\Hr\SubmissionStatus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Company self-administration (Sprint 5). Reuses the existing users table +
 * AuthService patterns for team management, audit_logs for login/activity
 * history, and NotificationService for invite/reset emails. Everything is scoped
 * to the ambient company — a company can never touch another's data.
 */
class CompanyAdminService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /* ── Profile & branding ── */

    public function profile(HrExternalCompany $c): array
    {
        return [
            'id' => $c->id, 'company_code' => $c->company_code, 'name' => $c->name, 'company_type' => $c->company_type,
            'industry' => $c->industry, 'company_size' => $c->company_size, 'website' => $c->website, 'logo_path' => $c->logo_path,
            'gst_number' => $c->gst_number, 'pan_number' => $c->pan_number, 'about' => $c->about, 'status' => $c->status,
            'contact_person' => $c->contact_person, 'contact_email' => $c->contact_email, 'contact_phone' => $c->contact_phone,
            'secondary_contact_person' => $c->secondary_contact_person, 'secondary_contact_email' => $c->secondary_contact_email,
            'secondary_contact_phone' => $c->secondary_contact_phone, 'billing_address' => $c->billing_address,
            'office_address' => $c->office_address, 'branding' => $c->branding ?? [], 'notification_settings' => $c->notification_settings ?? [],
        ];
    }

    public function updateProfile(HrExternalCompany $c, array $data, User $user): HrExternalCompany
    {
        $c->update($data);
        $c->recordAudit('Company profile updated', $user, null, ['client_visible' => false]);

        return $c->fresh();
    }

    public function uploadLogo(HrExternalCompany $c, \Illuminate\Http\UploadedFile $file, User $user): HrExternalCompany
    {
        if ($c->logo_path && Storage::disk('hr_documents')->exists($c->logo_path)) {
            Storage::disk('hr_documents')->delete($c->logo_path);
        }
        $path = $file->storeAs('tenant_'.$c->tenant_id.'/company_logos', 'logo_'.$c->id.'_'.time().'.'.strtolower($file->getClientOriginalExtension()), 'hr_documents');
        $c->update(['logo_path' => $path]);
        $c->recordAudit('Company logo updated', $user);

        return $c->fresh();
    }

    public function updateBranding(HrExternalCompany $c, array $branding, User $user): HrExternalCompany
    {
        $c->update(['branding' => $branding]);
        $c->recordAudit('Branding updated', $user);

        return $c->fresh();
    }

    public function updateNotificationSettings(HrExternalCompany $c, array $settings, User $user): HrExternalCompany
    {
        $c->update(['notification_settings' => $settings]);
        $c->recordAudit('Notification settings updated', $user);

        return $c->fresh();
    }

    /* ── Team management (reuses the users table) ── */

    public function users(HrExternalCompany $c)
    {
        return User::where('external_company_id', $c->id)->orderBy('id')
            ->get(['id', 'name', 'email', 'internal_role', 'status', 'phone', 'last_login_at', 'created_at'])
            ->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'role' => $u->internal_role, 'role_label' => CompanyRole::LABELS[$u->internal_role] ?? $u->internal_role,
                'status' => $u->status, 'phone' => $u->phone, 'last_login_at' => $u->last_login_at, 'created_at' => $u->created_at,
            ]);
    }

    public function createUser(HrExternalCompany $c, array $data, User $admin): array
    {
        if (! in_array($data['role'], CompanyRole::ASSIGNABLE, true)) {
            throw new BusinessException('Invalid role.', 422);
        }
        $tempPassword = Str::password(12);
        $user = User::create([
            'tenant_id' => $c->tenant_id, 'external_company_id' => $c->id,
            'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
            'password' => Hash::make($tempPassword), 'role' => 'company', 'internal_role' => $data['role'],
            'status' => 'active', 'company' => $c->name,
        ]);
        $c->recordAudit('Team member added: '.$user->name.' ('.(CompanyRole::LABELS[$data['role']] ?? $data['role']).')', $admin);

        $this->notifications->email($user->email, 'You have been invited to '.$c->name.' portal',
            "Hello {$user->name},\n\nAn account has been created for you on the {$c->name} recruitment portal.\n\nLogin email: {$user->email}\nTemporary password: {$tempPassword}\nRole: ".(CompanyRole::LABELS[$data['role']] ?? $data['role'])."\n\nPlease log in (select the \"Company\" role) and change your password.\n\n— {$c->name}",
            ['company_id' => $c->id, 'event' => 'team_invite']);

        return ['user' => $user, 'temp_password' => $tempPassword];
    }

    private function assertTarget(HrExternalCompany $c, User $target): void
    {
        abort_unless((int) $target->external_company_id === (int) $c->id, 404, 'User not found');
    }

    private function assertNotLastAdmin(HrExternalCompany $c, User $target): void
    {
        if ($target->internal_role === CompanyRole::ADMIN) {
            $admins = User::where('external_company_id', $c->id)->where('internal_role', CompanyRole::ADMIN)->where('status', 'active')->count();
            if ($admins <= 1) {
                throw new BusinessException('You cannot remove or demote the last active Company Admin.', 422);
            }
        }
    }

    public function assignRole(HrExternalCompany $c, User $target, string $role, User $admin): User
    {
        $this->assertTarget($c, $target);
        if (! in_array($role, CompanyRole::ASSIGNABLE, true)) {
            throw new BusinessException('Invalid role.', 422);
        }
        if ($target->internal_role === CompanyRole::ADMIN && $role !== CompanyRole::ADMIN) {
            $this->assertNotLastAdmin($c, $target);
        }
        $target->update(['internal_role' => $role]);
        $c->recordAudit('Role changed for '.$target->name.' → '.(CompanyRole::LABELS[$role] ?? $role), $admin);

        return $target->fresh();
    }

    public function setActive(HrExternalCompany $c, User $target, bool $active, User $admin): User
    {
        $this->assertTarget($c, $target);
        if ($target->id === $admin->id) {
            throw new BusinessException('You cannot change your own status.', 422);
        }
        if (! $active) {
            $this->assertNotLastAdmin($c, $target);
        }
        $target->update(['status' => $active ? 'active' : 'inactive']);
        if (! $active) {
            $target->tokens()->delete(); // force sign-out
        }
        $c->recordAudit(($active ? 'Activated' : 'Deactivated').' team member: '.$target->name, $admin);

        return $target->fresh();
    }

    public function removeUser(HrExternalCompany $c, User $target, User $admin): void
    {
        $this->assertTarget($c, $target);
        if ($target->id === $admin->id) {
            throw new BusinessException('You cannot remove yourself.', 422);
        }
        $this->assertNotLastAdmin($c, $target);
        $target->tokens()->delete();
        $c->recordAudit('Team member removed: '.$target->name, $admin);
        $target->delete();
    }

    public function resetPassword(HrExternalCompany $c, User $target, User $admin): string
    {
        $this->assertTarget($c, $target);
        $temp = Str::password(12);
        $target->forceFill(['password' => Hash::make($temp)])->save();
        $target->tokens()->delete();
        $c->recordAudit('Password reset for '.$target->name, $admin);
        $this->notifications->email($target->email, 'Your password was reset',
            "Hello {$target->name},\n\nYour password on the {$c->name} portal was reset.\n\nTemporary password: {$temp}\n\nPlease log in and change it.\n\n— {$c->name}",
            ['company_id' => $c->id, 'event' => 'password_reset']);

        return $temp;
    }

    /* ── Security (self) ── */

    public function changePassword(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw new BusinessException('Your current password is incorrect.', 422);
        }
        $user->forceFill(['password' => Hash::make($new)])->save();
    }

    public function sessions(User $user): array
    {
        $currentId = optional($user->currentAccessToken())->id;

        return $user->tokens()->latest('last_used_at')->get()->map(fn ($t) => [
            'id' => $t->id, 'name' => $t->name, 'last_used_at' => $t->last_used_at,
            'created_at' => $t->created_at, 'current' => $t->id === $currentId,
        ])->all();
    }

    public function loginHistory(User $user): array
    {
        return AuditLog::where('auditable_type', User::class)->where('auditable_id', $user->id)
            ->where('action', 'Signed in')->latest('id')->limit(20)->get()->map(fn ($a) => [
                'at' => $a->created_at, 'ip' => $a->metadata['ip'] ?? null, 'device' => $a->metadata['device'] ?? null,
            ])->all();
    }

    /* ── Reports (scoped to the company) ── */

    public function reports(HrExternalCompany $c): array
    {
        $requests = HrHiringRequest::where('external_company_id', $c->id)->get(['id', 'status', 'number_of_positions', 'assigned_recruiter_id', 'created_at', 'closed_at']);
        $reqIds = $requests->pluck('id');
        $delivered = HrCandidateSubmission::whereIn('hiring_request_id', $reqIds)->pluck('candidate_id')->unique()->values();

        $offerBy = fn ($s) => $delivered->isEmpty() ? 0 : HrOffer::whereIn('candidate_id', $delivered)->whereIn('status', $s)->count();
        $intBy = fn ($s) => $delivered->isEmpty() ? 0 : HrInterviewRound::whereIn('candidate_id', $delivered)->where('status', $s)->count();

        return [
            'hiring_summary' => [
                'total' => $requests->count(),
                'active' => $requests->where('status', Status::CONVERTED)->count(),
                'completed' => $requests->whereNotNull('closed_at')->count(),
                'open_positions' => (int) $requests->where('status', Status::CONVERTED)->sum('number_of_positions'),
            ],
            'interview_summary' => [
                'total' => $delivered->isEmpty() ? 0 : HrInterviewRound::whereIn('candidate_id', $delivered)->count(),
                'scheduled' => $intBy('Scheduled'), 'completed' => $intBy('Completed'), 'cancelled' => $intBy('Cancelled'),
            ],
            'offer_summary' => [
                'total' => $delivered->isEmpty() ? 0 : HrOffer::whereIn('candidate_id', $delivered)->count(),
                'pending' => $offerBy(['Generated', 'Sent', 'Viewed']), 'accepted' => $offerBy(['Accepted', 'Completed']), 'rejected' => $offerBy(['Declined', 'Expired']),
            ],
            'joining_summary' => [
                'joined' => $delivered->isEmpty() ? 0 : HrEmployee::whereIn('candidate_id', $delivered)->count(),
                'pending' => $delivered->isEmpty() ? 0 : HrOffer::whereIn('candidate_id', $delivered)->where('status', 'Accepted')->whereNull('joining_confirmed_at')->count(),
            ],
            'recruiter_performance' => $this->recruiterPerformance($requests, $reqIds),
            'monthly_hiring' => $this->monthlyHiring($requests),
        ];
    }

    private function recruiterPerformance($requests, $reqIds): array
    {
        $byRecruiter = $requests->whereNotNull('assigned_recruiter_id')->groupBy('assigned_recruiter_id');
        if ($byRecruiter->isEmpty()) {
            return [];
        }

        // Batch all lookups: recruiter names, and per-request delivered/accepted counts — 3 queries total.
        $names = User::whereIn('id', $byRecruiter->keys())->pluck('name', 'id');
        $submissions = HrCandidateSubmission::whereIn('hiring_request_id', $reqIds)
            ->selectRaw('hiring_request_id, count(*) as delivered, sum(case when status = ? then 1 else 0 end) as accepted', [SubmissionStatus::ACCEPTED])
            ->groupBy('hiring_request_id')->get()->keyBy('hiring_request_id');

        return $byRecruiter->map(function ($group, $rid) use ($names, $submissions) {
            $ids = $group->pluck('id');

            return [
                'recruiter' => $names[$rid] ?? 'Recruiter #'.$rid,
                'projects' => $group->count(),
                'candidates_delivered' => (int) $ids->sum(fn ($id) => (int) optional($submissions->get($id))->delivered),
                'accepted' => (int) $ids->sum(fn ($id) => (int) optional($submissions->get($id))->accepted),
                'closures' => $group->whereNotNull('closed_at')->count(),
            ];
        })->values()->all();
    }

    private function monthlyHiring($requests): array
    {
        $out = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $key = $month->format('Y-m');
            $out[] = [
                'month' => $month->format('M Y'),
                'requests' => $requests->filter(fn ($r) => $r->created_at && $r->created_at->format('Y-m') === $key)->count(),
                'closures' => $requests->filter(fn ($r) => $r->closed_at && $r->closed_at->format('Y-m') === $key)->count(),
            ];
        }

        return $out;
    }

    /* ── Activity log ── */

    public function activity(HrExternalCompany $c, int $page = 1): array
    {
        $reqIds = HrHiringRequest::where('external_company_id', $c->id)->pluck('id');
        $userIds = User::where('external_company_id', $c->id)->pluck('id');

        $q = AuditLog::where(function ($w) use ($reqIds, $c, $userIds) {
            $w->where(fn ($x) => $x->where('auditable_type', HrHiringRequest::class)->whereIn('auditable_id', $reqIds))
                ->orWhere(fn ($x) => $x->where('auditable_type', HrExternalCompany::class)->where('auditable_id', $c->id))
                ->orWhere(fn ($x) => $x->where('auditable_type', User::class)->whereIn('auditable_id', $userIds));
        })->latest('id')->paginate(20, ['*'], 'page', $page);

        $q->getCollection()->transform(fn ($a) => [
            'user'   => $a->actor_name ?? 'System',
            'action' => str_replace('_', ' ', $a->action),
            'at'     => $a->created_at,
            'ip'     => $a->metadata['ip'] ?? null,
            'device' => $a->metadata['device'] ?? null,
        ]);

        return ['data' => $q->items(), 'total' => $q->total(), 'current_page' => $q->currentPage(), 'last_page' => $q->lastPage()];
    }
}
