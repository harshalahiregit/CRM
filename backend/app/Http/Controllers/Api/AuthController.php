<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Auth\ClientRegisterRequest;
use App\Http\Requests\Auth\CompanyRegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\TPVRegisterRequest;
use App\Http\Requests\Auth\VendorRegisterRequest;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\AuthService;
use App\Services\Auth\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private AuthService $authService)
    {
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/login
     ───────────────────────────────────────────── */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());
        $user = $result['user'];

        return $this->success([
            'access_token' => $result['access_token'],
            'token_type'   => $result['token_type'],
            'user'         => (new UserResource($user))->resolve(),
            'tenant'       => $user->tenant ? (new TenantResource($user->tenant))->resolve() : null,
        ], 'Login successful');
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/register  (Admin / Tenant Owner)
     ───────────────────────────────────────────── */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return $this->success([
            'access_token' => $result['access_token'],
            'token_type'   => $result['token_type'],
            'user'         => (new UserResource($result['user']))->resolve(),
            'tenant'       => (new TenantResource($result['tenant']))->resolve(),
        ], 'Registration successful! Welcome to MLA CRM.', 201);
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/register/vendor
     ───────────────────────────────────────────── */
    public function registerVendor(VendorRegisterRequest $request): JsonResponse
    {
        $user = $this->authService->registerVendor($request->validated() + $request->only(
            'category', 'website', 'address', 'city', 'state', 'country', 'pincode', 'company_phone', 'manpower', 'msme'
        ));

        return $this->success([
            'user' => (new UserResource($user))->resolve(),
        ], 'Vendor registration submitted. Awaiting admin approval.', 201);
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/register/tpv
     ───────────────────────────────────────────── */
    public function registerTPV(TPVRegisterRequest $request): JsonResponse
    {
        $user = $this->authService->registerTPV($request->validated() + $request->only(
            'gst_number', 'city', 'state', 'country', 'zip', 'website'
        ));

        return $this->success([
            'user' => (new UserResource($user))->resolve(),
        ], 'Third-party vendor registration submitted. Awaiting admin approval.', 201);
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/register/client
     ───────────────────────────────────────────── */
    public function registerClient(ClientRegisterRequest $request): JsonResponse
    {
        $user = $this->authService->registerClient($request->validated() + $request->only(
            'address', 'city', 'state', 'country'
        ));

        return $this->success([
            'user' => (new UserResource($user))->resolve(),
        ], 'Client registration submitted. Awaiting admin approval.', 201);
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/register/company  (External Company self-registration)
     ───────────────────────────────────────────── */
    public function registerCompany(CompanyRegisterRequest $request): JsonResponse
    {
        $result = $this->authService->registerCompany($request->validated());

        return $this->success([
            'company_code' => $result['company']->company_code,
            'user'         => (new UserResource($result['user']))->resolve(),
        ], 'Company registration submitted. Awaiting HR approval — you will receive an email once activated.', 201);
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/logout
     ───────────────────────────────────────────── */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->success(null, 'Logged out successfully.');
    }

    /* ─────────────────────────────────────────────
     | GET /api/auth/me
     ───────────────────────────────────────────── */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('tenant');

        return $this->success([
            'user'   => (new UserResource($user))->resolve(),
            'tenant' => $user->tenant ? (new TenantResource($user->tenant))->resolve() : null,
        ]);
    }

    /* ── Session management (Phase 3) ──────────────────────────────── */

    /** GET /api/auth/sessions — the caller's active sessions. */
    public function sessions(Request $request, SessionService $sessions): JsonResponse
    {
        $currentTokenId = optional($request->user()->currentAccessToken())->id;

        return $this->success([
            'sessions'     => $sessions->listFor($request->user(), $currentTokenId),
            'idle_minutes' => (int) config('auth_sessions.idle_minutes', 30),
        ], 'Active sessions');
    }

    /** DELETE /api/auth/sessions/{session} — revoke one of the caller's sessions. */
    public function revokeSession(Request $request, UserSession $session, SessionService $sessions): JsonResponse
    {
        $sessions->revoke($request->user(), $session);

        return $this->success(null, 'Session revoked.');
    }

    /** POST /api/auth/sessions/logout-others — end every other session. */
    public function logoutOthers(Request $request, SessionService $sessions): JsonResponse
    {
        $currentTokenId = optional($request->user()->currentAccessToken())->id;
        $n = $sessions->revokeOthers($request->user(), $currentTokenId);

        return $this->success(['revoked' => $n], 'Signed out of other sessions.');
    }

    /** POST /api/auth/heartbeat — keep the current session alive (idle reset). */
    public function heartbeat(Request $request, SessionService $sessions): JsonResponse
    {
        $sessions->touch($request->user());

        return $this->success(null, 'ok');
    }

    /** POST /api/admin/users/{user}/force-logout — admin ends all a user's sessions. */
    public function forceLogout(Request $request, User $user, SessionService $sessions): JsonResponse
    {
        abort_unless((int) $user->tenant_id === (int) $request->user()->tenant_id, 404, 'User not found');

        $n = $sessions->forceLogout($user, $request->user());

        return $this->success(['revoked' => $n], 'User signed out of all devices.');
    }
}
