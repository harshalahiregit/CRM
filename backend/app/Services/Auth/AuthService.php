<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrExternalCompany;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AgencyContext;
use App\Support\Hr\CompanyAccountStatus;
use App\Support\Hr\CompanyType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthService
{
    public function login(array $data): array
    {
        $user = $this->findUserForLogin($data['email'], $data['role']);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            Log::channel('auth')->warning('Login failed: invalid credentials', ['email' => $data['email']]);
            throw new BusinessException('Invalid credentials. Please check your email and password.', 401);
        }

        $this->assertUserCanLogin($user);

        // Revoke old tokens (single device)
        $user->tokens()->delete();
        $token = $user->createToken('crm-auth-token', ['*'], now()->addDays(30))->plainTextToken;

        // Track last login; company logins also get an audit-based login history.
        $ip = request()->ip();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->saveQuietly();
        if ($user->role === 'company') {
            app(\App\Services\AuditLogService::class)->record($user, 'Signed in', $user, null, [
                'ip' => $ip, 'device' => request()->userAgent(), 'login' => true,
            ]);
        }

        Log::channel('auth')->info('User logged in', ['user_id' => $user->id, 'tenant_id' => $user->tenant_id]);

        return [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user->load('tenant'),
        ];
    }

    public function register(array $data): array
    {
        $slug = Str::slug($data['company']);
        $counter = 0;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = Str::slug($data['company']).'-'.++$counter;
        }

        $tenant = Tenant::create([
            'name'      => $data['company'],
            'slug'      => $slug,
            'subdomain' => $slug,
            'plan'      => 'starter',
            'status'    => 'active',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'role'      => 'admin',
            'status'    => 'active',
        ]);

        $token = $user->createToken('crm-auth-token', ['*'], now()->addDays(30))->plainTextToken;

        Log::channel('auth')->info('Tenant + admin registered', ['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        return [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
            'tenant'       => $tenant,
        ];
    }

    public function registerVendor(array $data): User
    {
        $user = User::create([
            'name'              => trim($data['first_name'].' '.$data['last_name']),
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'role'              => 'vendor',
            'status'            => 'pending',
            'vendor_type'       => $data['vendor_type'],
            'access_expires_at' => $data['vendor_type'] === 'temporary' ? now()->addDays(5)->toDateString() : null,
            'phone'             => $data['phone'] ?? null,
            'company'           => $data['company_name'],
            'designation'       => $data['designation'] ?? null,
            'meta'              => [
                'category'      => $data['category'] ?? null,
                'website'       => $data['website'] ?? null,
                'address'       => $data['address'] ?? null,
                'city'          => $data['city'] ?? null,
                'state'         => $data['state'] ?? null,
                'country'       => $data['country'] ?? null,
                'pincode'       => $data['pincode'] ?? null,
                'company_phone' => $data['company_phone'] ?? null,
                'manpower'      => $data['manpower'] ?? null,
                'msme'          => $data['msme'] ?? null,
            ],
        ]);

        Log::channel('auth')->info('Vendor registered, pending approval', ['user_id' => $user->id]);

        return $user;
    }

    public function registerTPV(array $data): User
    {
        $user = User::create([
            'name'              => trim($data['first_name'].' '.$data['last_name']),
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'role'              => 'third_party_vendor',
            'status'            => 'pending',
            'tpv_type'          => $data['tpv_type'],
            'access_expires_at' => $data['tpv_type'] === 'temporary' ? now()->addDays(5)->toDateString() : null,
            'phone'             => $data['phone'] ?? null,
            'designation'       => $data['position'] ?? null,
            'meta'              => [
                'username'   => $data['username'],
                'vat_number' => $data['vat_number'] ?? null,
                'industry'   => $data['industry'] ?? null,
                'city'       => $data['city'] ?? null,
                'state'      => $data['state'] ?? null,
                'country'    => $data['country'] ?? null,
                'zip'        => $data['zip'] ?? null,
                'website'    => $data['website'] ?? null,
            ],
        ]);

        Log::channel('auth')->info('TPV registered, pending approval', ['user_id' => $user->id]);

        return $user;
    }

    public function registerClient(array $data): User
    {
        $user = User::create([
            'name'     => trim($data['first_name'].' '.$data['last_name']),
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'role'     => 'client',
            'status'   => 'pending',
            'phone'    => $data['phone'],
            'company'  => $data['company'],
            'meta'     => [
                'address' => $data['address'] ?? null,
                'city'    => $data['city'] ?? null,
                'state'   => $data['state'] ?? null,
                'country' => $data['country'] ?? null,
            ],
        ]);

        Log::channel('auth')->info('Client registered, pending approval', ['user_id' => $user->id]);

        return $user;
    }

    /**
     * External company self-registration.
     *
     * Creates BOTH the company record and its admin login as Pending, in one
     * transaction, bound to the agency tenant (companies are clients of the
     * agency, not independent tenants). HR sees the pending company and approves
     * it — that flips both to Active. No tenant is assigned at approval.
     */
    public function registerCompany(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $tenantId = AgencyContext::tenantId();

            $company = HrExternalCompany::create([
                'tenant_id'      => $tenantId,
                'name'           => $data['company_name'],
                'company_type'   => $data['company_type'] ?? CompanyType::CLIENT,
                'industry'       => $data['industry'] ?? null,
                'company_size'   => $data['company_size'] ?? null,
                'website'        => $data['website'] ?? null,
                'gst_number'     => $data['gst_number'] ?? null,
                'pan_number'     => $data['pan_number'] ?? null,
                'contact_person' => $data['admin_name'],
                'contact_email'  => $data['admin_email'],
                'contact_phone'  => $data['admin_phone'] ?? null,
                'status'         => CompanyAccountStatus::PENDING_APPROVAL,
            ]);

            $user = User::create([
                'tenant_id'           => $tenantId,
                'external_company_id' => $company->id,
                'name'                => $data['admin_name'],
                'email'               => $data['admin_email'],
                'password'            => Hash::make($data['password']),
                'role'                => 'company',
                'internal_role'       => 'company_admin',
                'status'              => 'pending',
                'phone'               => $data['admin_phone'] ?? null,
                'company'             => $data['company_name'],
            ]);

            $company->recordAudit('Company registered — pending approval', null, $company->name, [
                'company_code' => $company->company_code, 'client_visible' => false,
            ]);
            Log::channel('auth')->info('Company registered, pending approval', [
                'company_id' => $company->id, 'user_id' => $user->id, 'tenant_id' => $tenantId,
            ]);

            return ['company' => $company, 'user' => $user];
        });
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
        Log::channel('auth')->info('User logged out', ['user_id' => $user->id]);
    }

    private function findUserForLogin(string $email, string $role): ?User
    {
        if ($role === 'staff') {
            return User::with('tenant')
                ->where('email', $email)
                ->where(function ($query) {
                    $query->where('role', 'staff')
                          ->orWhereIn('role', ['hr_executive', 'hiring_manager']); // Backward compatibility
                })
                ->first();
        }

        return User::with('tenant')
            ->where('email', $email)
            ->where('role', $role)
            ->first();
    }

    private function assertUserCanLogin(User $user): void
    {
        if ($user->isPending()) {
            throw new BusinessException('Your account is pending admin approval. You will receive an email once activated.', 403);
        }

        if ($user->status === 'suspended') {
            throw new BusinessException('Your account has been suspended. Contact support.', 403);
        }

        if ($user->status === 'rejected') {
            throw new BusinessException('Your registration was rejected. Contact support.', 403);
        }

        if ($user->access_expires_at && $user->access_expires_at->isPast()) {
            throw new BusinessException('Your temporary access has expired. Contact your administrator.', 403);
        }
    }
}
