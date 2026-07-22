<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\AgencyContext;
use App\Support\Vendor\VendorStatus;
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

        // Self-registered vendors land in the admin Dashboard immediately as a
        // Vendor record — Inactive until an admin activates them. They belong to
        // the agency tenant (external parties aren't independent tenants), and the
        // Temporary/Permanent choice maps to the vendor_type.
        Vendor::create([
            'tenant_id'   => AgencyContext::tenantId(),
            'user_id'     => $user->id,
            'company_name'=> $data['username'] ?? $user->name,
            'vendor_type' => $data['tpv_type'] === 'temporary' ? 'temporary' : 'standard',
            'engagements' => ['tpv'],
            'email'       => $user->email,
            'phone'       => $data['phone'] ?? null,
            'city'        => $data['city'] ?? null,
            'state'       => $data['state'] ?? null,
            'country'     => $data['country'] ?? null,
            'pincode'     => $data['zip'] ?? null,
            'status'      => VendorStatus::INACTIVE,
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
