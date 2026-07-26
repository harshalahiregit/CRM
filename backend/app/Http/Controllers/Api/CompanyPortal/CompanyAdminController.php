<?php

namespace App\Http\Controllers\Api\CompanyPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\User;
use App\Services\Hr\CompanyAdminService;
use App\Support\Hr\CompanyRole;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Company self-administration — profile, team, roles, notifications, branding,
 * reports, activity, security. Admin-only surfaces gate on companyCan('team'|
 * 'settings'); self-service (password/sessions) is open to any company user.
 * The ambient company (portal middleware) guarantees a company can only manage
 * its own organization.
 */
class CompanyAdminController extends Controller
{
    use ApiResponse, ResolvesPortalCompany;

    public function __construct(private CompanyAdminService $service)
    {
    }

    private function assertAdmin(Request $request): void
    {
        abort_unless($request->user()->isCompanyAdmin(), 403, 'Only a Company Admin may perform this action.');
    }

    private function assertCan(Request $request, string $area, string $ability = 'view'): void
    {
        abort_unless($request->user()->companyCan($area, $ability), 403, 'Your role does not permit this.');
    }

    /* ── Profile & branding ── */

    public function profile(Request $request)
    {
        return $this->success($this->service->profile($this->portalCompany($request)));
    }

    public function updateProfile(Request $request)
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'name'           => 'sometimes|string|max:200',
            'company_type'   => ['nullable', Rule::in(\App\Support\Hr\CompanyType::ALL)],
            'industry'       => 'nullable|string|max:120',
            'company_size'   => 'nullable|string|max:40',
            'website'        => 'nullable|string|max:200',
            'gst_number'     => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/'],
            'pan_number'     => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'],
            'about'          => 'nullable|string|max:5000',
            'contact_person' => 'nullable|string|max:120',
            'contact_email'  => 'nullable|email|max:150',
            'contact_phone'  => 'nullable|string|max:30',
            'secondary_contact_person' => 'nullable|string|max:120',
            'secondary_contact_email'  => 'nullable|email|max:150',
            'secondary_contact_phone'  => 'nullable|string|max:30',
            'billing_address' => 'nullable|string|max:2000',
            'office_address'  => 'nullable|string|max:2000',
        ]);

        return $this->success($this->service->updateProfile($this->portalCompany($request), $data, $request->user()));
    }

    public function uploadLogo(Request $request)
    {
        $this->assertAdmin($request);
        // SVG excluded on purpose — an inline-served SVG can carry script (XSS).
        $request->validate(['logo' => 'required|file|mimes:png,jpg,jpeg,webp|max:2048']);

        return $this->success($this->service->uploadLogo($this->portalCompany($request), $request->file('logo'), $request->user()), 'Logo updated');
    }

    public function updateBranding(Request $request)
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'primary_color'   => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'email_footer'    => 'nullable|string|max:2000',
        ]);

        return $this->success($this->service->updateBranding($this->portalCompany($request), $data, $request->user()), 'Branding saved');
    }

    public function updateNotifications(Request $request)
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'email'               => 'boolean',
            'interview_updates'   => 'boolean',
            'offer_updates'       => 'boolean',
            'hiring_updates'      => 'boolean',
            'recruiter_messages'  => 'boolean',
            'candidate_submission' => 'boolean',
        ]);

        return $this->success($this->service->updateNotificationSettings($this->portalCompany($request), $data, $request->user()), 'Notification settings saved');
    }

    /* ── Team management (admin) ── */

    public function users(Request $request)
    {
        $this->assertAdmin($request);

        return $this->success($this->service->users($this->portalCompany($request)));
    }

    public function createUser(Request $request)
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'name'  => 'required|string|max:120',
            'email' => 'required|email|max:150|unique:users,email',
            'phone' => 'nullable|string|max:30',
            'role'  => ['required', Rule::in(CompanyRole::ASSIGNABLE)],
        ]);
        $res = $this->service->createUser($this->portalCompany($request), $data, $request->user());

        return $this->success(['id' => $res['user']->id], 'Team member invited — credentials emailed', 201);
    }

    public function assignRole(Request $request, User $user)
    {
        $this->assertAdmin($request);
        $data = $request->validate(['role' => ['required', Rule::in(CompanyRole::ASSIGNABLE)]]);

        return $this->success($this->service->assignRole($this->portalCompany($request), $user, $data['role'], $request->user()), 'Role updated');
    }

    public function setStatus(Request $request, User $user)
    {
        $this->assertAdmin($request);
        $data = $request->validate(['active' => 'required|boolean']);

        return $this->success($this->service->setActive($this->portalCompany($request), $user, $data['active'], $request->user()), 'Status updated');
    }

    public function resetUserPassword(Request $request, User $user)
    {
        $this->assertAdmin($request);
        $this->service->resetPassword($this->portalCompany($request), $user, $request->user());

        return $this->success(null, 'Password reset — new credentials emailed');
    }

    public function removeUser(Request $request, User $user)
    {
        $this->assertAdmin($request);
        $this->service->removeUser($this->portalCompany($request), $user, $request->user());

        return $this->success(null, 'Team member removed');
    }

    /* ── Reports & activity ── */

    public function reports(Request $request)
    {
        $this->assertCan($request, 'reports', 'view');

        return $this->success($this->service->reports($this->portalCompany($request)));
    }

    public function activity(Request $request)
    {
        $this->assertAdmin($request);

        return $this->success($this->service->activity($this->portalCompany($request), (int) $request->query('page', 1)));
    }

    /* ── Security (self-service, any company user) ── */

    public function sessions(Request $request)
    {
        return $this->success([
            'last_login_at' => $request->user()->last_login_at,
            'last_login_ip' => $request->user()->last_login_ip,
            'sessions'      => $this->service->sessions($request->user()),
            'history'       => $this->service->loginHistory($request->user()),
        ]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);
        $this->service->changePassword($request->user(), $data['current_password'], $data['password']);

        return $this->success(null, 'Password changed');
    }
}
