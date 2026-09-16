<?php

namespace App\Http\Controllers\Api\Hrm;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeIdentityService;
use App\Support\Hrm\HrmAvatar;
use App\Support\Hrm\HrmResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Signing in from the attendance app.
 *
 * The response shape is derived from the app's own LoginResponse model, since
 * SangoeTrack's source is not available — the Dart parsing IS the specification.
 *
 * Two things there are load-bearing and easy to get wrong:
 *
 *   `data.workspaces` must be a non-null ARRAY. LoginController does
 *   `loginResponse.data!.workspaces!.length` — a non-null assertion — so
 *   omitting the key crashes the app outright rather than degrading.
 *
 *   `data.user` likewise: `data!.user!.id` is asserted. Every field the model
 *   reads is sent, even when empty, because a missing key renders blank and a
 *   blank profile looks like data loss to whoever is holding the phone.
 */
class HrmAuthController extends Controller
{
    public function __construct(private EmployeeIdentityService $identity)
    {
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        // One message for both cases: saying which half was wrong tells somebody
        // whether an address is registered.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return HrmResponse::fail('Those details did not match. Check your email and password.');
        }

        if ($user->status !== 'active') {
            return HrmResponse::fail('This account is not active. Contact HR.');
        }

        // App access is HR's decision, held on the employee record. Being able to
        // sign into the CRM is a different permission from clocking in on a phone.
        $employee = $this->identity->employeeFor($user);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        if (! $this->identity->mayUseApp($user)) {
            return HrmResponse::fail(
                $this->identity->appRefusalReason($user) ?: 'You do not have access to the attendance app. Contact HR.'
            );
        }

        $token = $user->createToken('sangoe-app')->plainTextToken;

        return HrmResponse::ok([
            'token' => $token,
            'user'  => $this->userPayload($user, $employee),
            // Never null — the app asserts on this.
            'workspaces' => $this->workspaces($user),
        ], 'Signed in.');
    }

    public function logout(Request $request)
    {
        // Only this device's token, not every session the person has.
        $request->user()?->currentAccessToken()?->delete();

        return HrmResponse::ok([], 'Signed out.');
    }

    /**
     * Their app calls this to extend a session.
     *
     * SangoeTrack's tokens never expire — TTL null, no exp claim, and their
     * refresh class is empty — so the app calls this and carries on regardless.
     * Issuing a fresh token and retiring the old one is the honest version of
     * the same contract.
     */
    public function refresh(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return HrmResponse::unauthenticated();
        }

        $request->user()?->currentAccessToken()?->delete();
        $token = $user->createToken('sangoe-app')->plainTextToken;

        $employee = $this->identity->employeeFor($user);

        return HrmResponse::ok([
            'token'      => $token,
            'user'       => $this->userPayload($user, $employee),
            'workspaces' => $this->workspaces($user),
        ]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return HrmResponse::fail('Your current password is not right.');
        }

        $user->forceFill(['password' => Hash::make($data['new_password'])])->save();

        return HrmResponse::ok([], 'Password changed.');
    }

    /* ── payload shapes, from the app's models ───────────────────────── */

    /**
     * Every field User.fromJson reads, always present.
     *
     * `type` drives which screens the app shows, so it maps the CRM's role
     * rather than being left blank — an empty type would give an admin the
     * employee-only app.
     */
    private function userPayload(User $user, $employee): array
    {
        return [
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'mobile_no'        => $user->phone ?? '',
            'type'             => $user->role === 'admin' ? 'company' : 'employee',
            'active_workspace' => $user->tenant_id,
            'avatar'           => HrmAvatar::url($user->avatar),
            'lang'             => 'en',
            // Beyond their model, and harmless: extra keys are ignored by
            // fromJson, and having the employee code saves a second call.
            'employee_id'      => $employee?->id,
            'employee_code'    => $employee?->employee_code,
            'department'       => $employee?->department ?? '',
            'designation'      => $employee?->designation ?? '',
        ];
    }

    /** The tenant, in their Workspace shape. */
    private function workspaces(User $user): array
    {
        $tenant = Tenant::find($user->tenant_id);

        if (! $tenant) {
            return [];
        }

        return [[
            'id'         => $tenant->id,
            'name'       => $tenant->name,
            'slug'       => $tenant->slug,
            'status'     => $tenant->status,
            'created_by' => null,
            'logo'       => $this->tenantLogo($tenant),
        ]];
    }

    /**
     * The company logo the app shows in both dashboard headers.
     *
     * This was hardcoded to '' — so the header on every phone fell back to a
     * placeholder and looked like a broken image, and the only way to change it
     * would have been to edit this file. Settings > General > Branding already
     * has a Logo URL field; read that, and keep tenants.logo_url as the older
     * fallback so a workspace configured before the settings screen existed
     * still shows its logo.
     *
     * Returned absolute, because the app hands the string straight to
     * Image.network and a site-relative path there resolves against nothing.
     */
    private function tenantLogo(Tenant $tenant): string
    {
        $settings = app(\App\Services\Settings\SettingsService::class);
        $logo = $settings->get($tenant->id, 'branding', 'logo_url') ?: $tenant->logo_url;
        $logo = trim((string) $logo);

        if ($logo === '') {
            return '';
        }

        if (preg_match('#^(https?:)?//#i', $logo) === 1 || str_starts_with($logo, 'data:')) {
            return $logo;
        }

        return url('/' . ltrim($logo, '/'));
    }
}
