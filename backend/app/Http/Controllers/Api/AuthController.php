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
use App\Services\Auth\AuthService;
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
            'vat_number', 'city', 'state', 'country', 'zip', 'website'
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
}
