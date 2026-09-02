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
use App\Services\Notifications\NotificationService;
use App\Services\Auth\SessionService;
use App\Support\FrontendUrl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

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
        // validated() carries the rules above; only() passes the address/identity
        // fields the form also collects. legal_name/pan_number/address ARE
        // validated, so they come through validated() and need no entry here.
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
     | POST /api/auth/forgot-password   (public)
     |
     | Mints the SAME one-time token setPassword() below already consumes, and
     | emails the same /auth/set-password link the vendor login-link action sends.
     | One token table, one consuming endpoint, one landing page — a separate
     | reset pipeline would be a second thing to keep in step.
     ───────────────────────────────────────────── */
    public function forgotPassword(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);
        $email = $data['email'];

        $user = User::where('email', $email)->first();

        // Only a real, active account gets a link — but the RESPONSE is identical
        // either way. Saying "no such account" would turn this endpoint into a
        // way to test which addresses are registered.
        if ($user && $user->status === 'active') {
            $token = Str::random(64);

            // One live token per address: issuing a second retires the first, or an
            // older email in the inbox keeps working after the newer one is used.
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $expiry = (int) config('auth.passwords.users.expire', 60);
            $url = FrontendUrl::to('/auth/set-password', ['token' => $token, 'email' => $email]);

            $notifications->email(
                $email,
                'Reset your password',
                implode("\n", [
                    'Hello '.($user->name ?: '').',',
                    '',
                    'We received a request to reset the password for this account.',
                    '',
                    'Set a new password using the link below:',
                    $url,
                    '',
                    'This link can be used once and expires in '.$expiry.' minutes.',
                    'If you did not request this, you can ignore this email — your current password still works.',
                ]),
                ['user_id' => $user->id]
            );

            Log::info('Password reset link issued', ['user_id' => $user->id]);
        }

        return $this->success(null, 'If that email is registered, a reset link has been sent.');
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/set-password   (public)
     |
     | Consumes the one-time token from a vendor login-link OR a forgot-password
     | email — both mint the same token. Public by necessity: the recipient has
     | no session yet, which is the whole point.
     ───────────────────────────────────────────── */
    public function setPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'token'    => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $row = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        // One message for every failure mode — wrong token, no token, expired,
        // unknown email. Distinguishing them would let someone probe which
        // addresses have a pending invitation.
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        $valid = $row
            && Hash::check($data['token'], $row->token)
            && Carbon::parse($row->created_at)->addMinutes($expireMinutes)->isFuture();

        if (! $valid) {
            return $this->error('This link is invalid or has expired. Ask for a new one.', 422);
        }

        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            return $this->error('This link is invalid or has expired. Ask for a new one.', 422);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        // Burn the token: a set-password link must work exactly once, or a
        // forwarded email stays a standing key to the account.
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        // Existing sessions are cut. Whoever set this password is the one who
        // should be signed in — any other live token predates it.
        $user->tokens()->delete();

        // Logged, not recordAudit(): User does not use the Auditable trait, so
        // that call would be a fatal. The log line is the trail.
        Log::info('Portal password set via one-time link', [
            'user_id' => $user->id, 'tenant_id' => $user->tenant_id,
        ]);

        return $this->success(null, 'Password set. Please sign in.');
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
    /**
     * The signed-in user's own profile.
     *
     * There was no such endpoint, and no page behind the "My Profile" item in
     * the global user menu — it navigated to /app/settings/profile, a route
     * that does not exist, so every user on every screen got a 404 from the
     * most obvious thing in the header.
     *
     * Deliberately narrow. A user may correct how they appear to colleagues;
     * they may not edit their own role, tenant, status or access expiry, all
     * of which are on the same model and all of which would be a privilege
     * escalation. Email is excluded too — it is the login identity, and
     * changing it is an administrative act with verification attached.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'phone'           => ['nullable', 'string', 'max:30', new \App\Rules\PhoneNumber()],
            'designation'     => 'nullable|string|max:120',
            'department'      => 'nullable|string|max:120',
            'emails_enabled'  => 'nullable|boolean',
            // ST1 — mail_from_name / mail_from_email are NOT settable here.
            // TenantMailer uses them verbatim as the From address on outgoing
            // mail, so a self-service field let any signed-in user send as
            // anyone. An admin sets them on the staff record instead
            // (StaffManagementController::update). Left readable below so the
            // profile screen can show what was configured for you.
        ]);

        $user->fill($data)->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Profile updated',
            'data'    => $user->fresh()->only([
                'id', 'name', 'email', 'phone', 'designation', 'department',
                'role', 'internal_role', 'emails_enabled', 'mail_from_name', 'mail_from_email',
            ]),
        ]);
    }

    /**
     * Change your own password.
     *
     * The current password is required: a token alone is not proof of the
     * person, and an unattended session should not be enough to lock the owner
     * out of their own account.
     */
    public function changePassword(Request $request, SessionService $sessions): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        if (! \Illuminate\Support\Facades\Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Your current password is not correct.',
            ], 422);
        }

        // No last_password_change column on users — that one lives on
        // client_contacts. The audit trail records the event instead.
        $user->forceFill([
            'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
        ])->save();

        // Other sessions were opened with the old password and are no longer
        // trustworthy — changing it is exactly what someone does when they
        // fear it was known. The current session is deliberately kept, so the
        // act of securing the account does not sign you out of it.
        $sessions->revokeOthers($user, optional($request->user()->currentAccessToken())->id);

        return response()->json(['status' => 'success', 'message' => 'Password changed']);
    }

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
