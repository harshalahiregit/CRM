<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /* ─────────────────────────────────────────────
     | POST /api/auth/login
     ───────────────────────────────────────────── */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|string',
            'role'     => 'required|in:admin,vendor,third_party_vendor,client',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        $user = User::with('tenant')
            ->where('email', $request->email)
            ->where('role', $request->role)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->error('Invalid credentials. Please check your email and password.', 401);
        }

        if ($user->isPending()) {
            return $this->error('Your account is pending admin approval. You will receive an email once activated.', 403);
        }

        if ($user->status === 'suspended') {
            return $this->error('Your account has been suspended. Contact support.', 403);
        }

        if ($user->status === 'rejected') {
            return $this->error('Your registration was rejected. Contact support.', 403);
        }

        // Check if temporary access expired
        if ($user->access_expires_at && $user->access_expires_at->isPast()) {
            return $this->error('Your temporary access has expired. Contact your administrator.', 403);
        }

        // Revoke old tokens (single device)
        $user->tokens()->delete();

        $token = $user->createToken('crm-auth-token', ['*'], now()->addDays(30))->plainTextToken;

        return $this->success([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $this->formatUser($user),
            'tenant'       => $user->tenant ? $this->formatTenant($user->tenant) : null,
        ], 'Login successful');
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/register  (Admin / Tenant Owner)
     ───────────────────────────────────────────── */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|min:2|max:100',
            'email'    => 'required|email|unique:users,email',
            'company'  => 'required|string|min:2|max:150',
            'password' => ['required', 'confirmed', Password::min(8)],
            'terms'    => 'accepted',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        // Create tenant
        $slug = Str::slug($request->company);
        $counter = 0;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = Str::slug($request->company) . '-' . ++$counter;
        }

        $tenant = Tenant::create([
            'name'      => $request->company,
            'slug'      => $slug,
            'subdomain' => $slug,
            'plan'      => 'starter',
            'status'    => 'active',
        ]);

        // Create admin user (active immediately)
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'role'      => 'admin',
            'status'    => 'active',
        ]);

        $token = $user->createToken('crm-auth-token', ['*'], now()->addDays(30))->plainTextToken;

        return $this->success([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $this->formatUser($user),
            'tenant'       => $this->formatTenant($tenant),
        ], 'Registration successful! Welcome to MLA CRM.', 201);
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/register/vendor
     ───────────────────────────────────────────── */
    public function registerVendor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name'   => 'required|string|min:2',
            'last_name'    => 'required|string|min:1',
            'email'        => 'required|email|unique:users,email',
            'company_name' => 'required|string|min:2',
            'password'     => ['required', 'confirmed', Password::min(8)],
            'vendor_type'  => 'required|in:standard,temporary',
            'phone'        => 'nullable|string',
            'designation'  => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        $expiresAt = null;
        if ($request->vendor_type === 'temporary') {
            $expiresAt = now()->addDays(5)->toDateString();
        }

        $user = User::create([
            'name'             => trim($request->first_name . ' ' . $request->last_name),
            'email'            => $request->email,
            'password'         => Hash::make($request->password),
            'role'             => 'vendor',
            'status'           => 'pending',
            'vendor_type'      => $request->vendor_type,
            'access_expires_at'=> $expiresAt,
            'phone'            => $request->phone,
            'company'          => $request->company_name,
            'designation'      => $request->designation,
            'meta'             => [
                'category'      => $request->category,
                'website'       => $request->website,
                'address'       => $request->address,
                'city'          => $request->city,
                'state'         => $request->state,
                'country'       => $request->country,
                'pincode'       => $request->pincode,
                'company_phone' => $request->company_phone,
                'manpower'      => $request->manpower,
                'msme'          => $request->msme,
            ],
        ]);

        return $this->success([
            'user' => $this->formatUser($user),
        ], 'Vendor registration submitted. Awaiting admin approval.', 201);
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/register/tpv
     ───────────────────────────────────────────── */
    public function registerTPV(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|min:2',
            'last_name'  => 'required|string|min:1',
            'email'      => 'required|email|unique:users,email',
            'password'   => ['required', 'confirmed', Password::min(8)],
            'tpv_type'   => 'required|in:permanent,temporary',
            'username'   => 'required|string|unique:users,meta->username',
            'phone'      => 'nullable|string',
            'industry'   => 'nullable|string',
            'position'   => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        $expiresAt = null;
        if ($request->tpv_type === 'temporary') {
            $expiresAt = now()->addDays(5)->toDateString();
        }

        $user = User::create([
            'name'             => trim($request->first_name . ' ' . $request->last_name),
            'email'            => $request->email,
            'password'         => Hash::make($request->password),
            'role'             => 'third_party_vendor',
            'status'           => 'pending',
            'tpv_type'         => $request->tpv_type,
            'access_expires_at'=> $expiresAt,
            'phone'            => $request->phone,
            'designation'      => $request->position,
            'meta'             => [
                'username'   => $request->username,
                'vat_number' => $request->vat_number,
                'industry'   => $request->industry,
                'city'       => $request->city,
                'state'      => $request->state,
                'country'    => $request->country,
                'zip'        => $request->zip,
                'website'    => $request->website,
            ],
        ]);

        return $this->success([
            'user' => $this->formatUser($user),
        ], 'Third-party vendor registration submitted. Awaiting admin approval.', 201);
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/register/client
     ───────────────────────────────────────────── */
    public function registerClient(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|min:2',
            'last_name'  => 'required|string|min:1',
            'email'      => 'required|email|unique:users,email',
            'company'    => 'required|string|min:2',
            'phone'      => 'required|string|min:7',
            'password'   => ['required', 'confirmed', Password::min(8)],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        $user = User::create([
            'name'    => trim($request->first_name . ' ' . $request->last_name),
            'email'   => $request->email,
            'password'=> Hash::make($request->password),
            'role'    => 'client',
            'status'  => 'pending',
            'phone'   => $request->phone,
            'company' => $request->company,
            'meta'    => [
                'address' => $request->address,
                'city'    => $request->city,
                'state'   => $request->state,
                'country' => $request->country,
            ],
        ]);

        return $this->success([
            'user' => $this->formatUser($user),
        ], 'Client registration submitted. Awaiting admin approval.', 201);
    }

    /* ─────────────────────────────────────────────
     | POST /api/auth/logout
     ───────────────────────────────────────────── */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return $this->success(null, 'Logged out successfully.');
    }

    /* ─────────────────────────────────────────────
     | GET /api/auth/me
     ───────────────────────────────────────────── */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('tenant');
        return $this->success([
            'user'   => $this->formatUser($user),
            'tenant' => $user->tenant ? $this->formatTenant($user->tenant) : null,
        ]);
    }

    /* ─────────────────────────────────────────────
     | PRIVATE HELPERS
     ───────────────────────────────────────────── */
    private function formatUser(User $user): array
    {
        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => $user->role,
            'status'      => $user->status,
            'vendor_type' => $user->vendor_type,
            'tpv_type'    => $user->tpv_type,
            'phone'       => $user->phone,
            'company'     => $user->company,
            'designation' => $user->designation,
            'avatar'      => $user->avatar,
            'tenant_id'   => $user->tenant_id,
            'created_at'  => $user->created_at,
        ];
    }

    private function formatTenant(Tenant $tenant): array
    {
        return [
            'id'              => $tenant->id,
            'name'            => $tenant->name,
            'slug'            => $tenant->slug,
            'subdomain'       => $tenant->subdomain,
            'plan'            => $tenant->plan,
            'status'          => $tenant->status,
            'branding_color'  => $tenant->branding_color,
        ];
    }

    private function success($data, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    private function error(string $message, int $code = 400, $errors = null): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
            'errors'  => $errors,
        ], $code);
    }
}
