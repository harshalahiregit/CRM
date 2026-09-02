<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrExternalCompany;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Purchase\PurchaseVendorService;
use App\Support\AgencyContext;
use App\Support\Purchase\PurchaseRegistrationType;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Vendor\VendorStatus;
use App\Support\Hr\CompanyAccountStatus;
use App\Support\Hr\CompanyType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthService
{
    public function login(array $data): array
    {
        // Lockout after repeated failures.
        //
        // The Security Settings page has always offered "Failed logins before
        // lockout" and "Lockout duration", saved them, and read them back — while
        // this method had no throttling of ANY kind. Ten wrong passwords cost an
        // attacker nothing. That was a real gap, not just a decorative toggle.
        //
        // Keyed on email + IP so one attacker cannot lock a real user out of
        // their own account by guessing at them from elsewhere.
        $throttleKey = 'login:'.strtolower($data['email']).'|'.request()->ip();

        $user = $this->findUserForLogin($data['email'], $data['role']);
        [$maxAttempts, $decayMinutes] = $this->lockoutPolicy($user);

        if ($maxAttempts > 0 && RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::channel('auth')->warning('Login blocked: locked out', [
                'email' => $data['email'], 'ip' => request()->ip(), 'retry_in' => $seconds,
            ]);

            throw new BusinessException(
                'Too many failed attempts. Try again in '.max(1, (int) ceil($seconds / 60)).' minute(s).',
                429,
            );
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            if ($maxAttempts > 0) {
                RateLimiter::hit($throttleKey, $decayMinutes * 60);
            }
            Log::channel('auth')->warning('Login failed: invalid credentials', ['email' => $data['email']]);
            throw new BusinessException('Invalid credentials. Please check your email and password.', 401);
        }

        // A good password clears the counter — the lockout is for guessing, not
        // for someone who mistyped twice and then got it right.
        RateLimiter::clear($throttleKey);

        $this->assertUserCanLogin($user);

        // Establish a tracked session (applies the concurrency policy — default
        // 'single' preserves the prior one-device behaviour) and issue the token.
        $ua = \App\Support\UserAgentInfo::parse(request()->userAgent());
        $token = app(\App\Services\Auth\SessionService::class)->establish(
            $user,
            (bool) ($data['remember'] ?? false),
            ['ip' => request()->ip(), 'browser' => $ua['browser'], 'device' => $ua['device']],
        );

        // Track last login; company logins also get an audit-based login history.
        $ip = request()->ip();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->saveQuietly();
        if ($user->role === 'company') {
            app(\App\Services\AuditLogService::class)->record($user, 'Signed in', $user, null, [
                'ip' => $ip, 'device' => request()->userAgent(), 'login' => true,
            ]);
        }

        // TPV keeps its own sign-in counters on the vendor row (no-ops for every
        // other role). Purchase tracks its portal separately on purchase_vendors.
        app(\App\Services\Tpv\TpvLoginTracker::class)->record($user);

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

    /**
     * Vendor self-registration. Creates the login User *and* — in the same
     * transaction — the Purchase-owned PurchaseVendor record, so the vendor
     * lands in Purchase → Vendors immediately (Draft, awaiting activation),
     * exactly like the TPV flow. Purchase owns purchase_vendors; no shared
     * Vendor row and no staging table is involved.
     */
    public function registerVendor(array $data): User
    {
        return DB::transaction(fn () => $this->createVendorAccount($data));
    }

    private function createVendorAccount(array $data): User
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

        $this->createPurchaseVendorFor($user, $data);

        Log::channel('auth')->info('Vendor registered, pending approval', ['user_id' => $user->id]);

        return $user;
    }

    /**
     * Mirror the registration into the Purchase module's own vendor master so
     * the record shows up in Purchase → Vendors straight away — the same way a
     * TPV registration lands in the TPV list.
     *
     * Purchase-owned end to end: purchase_vendors only, the Purchase code
     * generator, the Purchase registration-type enum. No shared Vendor row, no
     * staging/pending table, and nothing from TPV.
     */
    private function createPurchaseVendorFor(User $user, array $data): void
    {
        $tenantId = AgencyContext::tenantId();

        // Idempotent: never mint a second record for the same tenant + email.
        if (PurchaseVendor::withTrashed()->where('tenant_id', $tenantId)->where('email', $user->email)->exists()) {
            return;
        }

        $vendor = PurchaseVendor::create([
            'tenant_id'            => $tenantId,
            'user_id'              => $user->id,
            'purchase_vendor_code' => app(PurchaseVendorService::class)->nextVendorCode($tenantId),
            'company_name'         => $data['company_name'],
            'email'                => $user->email,
            'phone'                => $data['phone'] ?? null,
            'website'              => $data['website'] ?? null,
            'category'             => $data['category'] ?? null,
            'address'              => $data['address'] ?? null,
            'city'                 => $data['city'] ?? null,
            'state'                => $data['state'] ?? null,
            'country'              => $data['country'] ?? null,
            'pincode'              => $data['pincode'] ?? null,
            'vendor_type'          => $data['vendor_type'] === 'temporary' ? 'temporary' : 'standard',
            // The chooser's selection, stored verbatim — never inferred later.
            'registration_type'    => PurchaseRegistrationType::normalize($data['vendor_type'] ?? null),
            // Awaiting admin activation: Draft is the status Purchase already
            // uses for "created but not yet transactable".
            'status'               => PurchaseVendorStatus::DRAFT,
            // Registered, but the portal stays shut until an admin activates —
            // provision() flips this to 'active' on approval.
            'portal_status'        => 'Registered',
            // Same credentials, so after activation they sign into the Purchase
            // portal as this very PurchaseVendor (no second account).
            'password'             => Hash::make($data['password']),
        ]);

        Log::channel('purchase')->info('Purchase vendor created from self-registration', [
            'purchase_vendor_id' => $vendor->id, 'user_id' => $user->id,
            'registration_type'  => $vendor->registration_type,
        ]);
    }

    public function registerTPV(array $data): User
    {
        // Resolved once and stamped on BOTH rows, exactly as registerCompany does.
        // The Vendor row has always carried it; the User row did not, which left
        // users.tenant_id NULL on every self-registered TPV. Anything that scopes
        // by the caller's tenant then resolved nothing -- including the portal
        // countdown lookup (Vendor::forTenant($user->tenant_id)), so a temporary
        // TPV never saw its own access window.
        $tenantId = AgencyContext::tenantId();

        $user = User::create([
            'tenant_id'         => $tenantId,
            'name'              => trim($data['first_name'].' '.$data['last_name']),
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'role'              => 'third_party_vendor',
            'status'            => 'pending',
            'tpv_type'          => $data['tpv_type'],
            // A temporary vendor's 5-day access window starts at admin activation,
            // not at registration — so it is null here and set in
            // VendorService::updateStatus when the admin approves.
            'access_expires_at' => null,
            'phone'             => $data['phone'] ?? null,
            'designation'       => $data['position'] ?? null,
            // Kept as the raw record of what was typed at registration. The vendor
            // master below is the authoritative copy that listings, PDFs and search
            // read; this stays so the backfill has a source for older rows.
            'meta'              => [
                'username'    => $data['username'],
                'gst_number'  => $data['gst_number'] ?? null,
                'industry'    => $data['industry'] ?? null,
                'city'        => $data['city'] ?? null,
                'state'       => $data['state'] ?? null,
                'country'     => $data['country'] ?? null,
                'zip'         => $data['zip'] ?? null,
                'website'     => $data['website'] ?? null,
                'legal_name'  => $data['legal_name'] ?? null,
                'pan_number'  => $data['pan_number'] ?? null,
                'address'     => $data['address'] ?? null,
            ],
        ]);

        // Self-registered vendors land in the admin Dashboard immediately as a
        // Vendor record — Inactive until an admin activates them. They belong to
        // the agency tenant (external parties aren't independent tenants), and the
        // Temporary/Permanent choice maps to the vendor_type.
        Vendor::create([
            'tenant_id'   => $tenantId,
            'user_id'     => $user->id,
            'company_name'=> $data['username'] ?? $user->name,
            // Store on the vendor's own column, not just in user meta --
            // the vendor master is what listings, PDFs and search read.
            'gst_number'  => $data['gst_number'] ?? null,
            // These four were collected at registration and written ONLY to
            // user.meta, so the vendor record showed a dash for details the
            // vendor had already given us. `industry` is the registration form's
            // word for what the vendor master calls `category`.
            'legal_name'  => $data['legal_name'] ?? null,
            'pan_number'  => isset($data['pan_number']) ? strtoupper((string) $data['pan_number']) : null,
            'address'     => $data['address'] ?? null,
            'website'     => $data['website'] ?? null,
            'category'    => $data['industry'] ?? null,
            'vendor_type' => $data['tpv_type'] === 'temporary' ? 'temporary' : 'standard',
            // The registration choice is stored verbatim, so it is never
            // re-derived from vendor_type / is_temporary later on.
            'registration_type' => \App\Support\Tpv\TpvRegistrationType::normalize($data['tpv_type'] ?? null),
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
        app(\App\Services\Auth\SessionService::class)->endCurrent($user);
        Log::channel('auth')->info('User logged out', ['user_id' => $user->id]);
    }

    /**
     * [attempts before lockout, lockout minutes] for this user's tenant.
     *
     * Read from the tenant's Security settings when the email matches a real
     * user; an unknown email falls back to the registry default so that probing
     * for valid addresses is still throttled. 0 attempts disables the lockout,
     * which is what the setting's own `min:0` means.
     */
    private function lockoutPolicy(?User $user): array
    {
        $max   = 5;
        $decay = 15;

        if ($user?->tenant_id) {
            try {
                $security = app(\App\Services\Settings\SettingsService::class)
                    ->getGroup((int) $user->tenant_id, 'security');
                $max   = (int) ($security['failed_login_lockout'] ?? $max);
                $decay = (int) ($security['lockout_duration_minutes'] ?? $decay);
            } catch (\Throwable $e) {
                // A settings failure must not disable the lockout; keep defaults.
                Log::channel('auth')->warning('Security settings unreadable; using default lockout', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [max(0, $max), max(1, $decay)];
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

    /**
     * Only an ACTIVE account may sign in.
     *
     * This was a denylist of pending / suspended / rejected, and it missed
     * 'inactive' — the status the Deactivate button in Staff Management actually
     * writes (StaffManagementController::toggleStatus), and the one
     * VendorService and TpvAccessService write when revoking access. So the
     * control that exists to stop someone signing in was the one status sign-in
     * ignored.
     *
     * It is an allowlist now, which fails in the safe direction: a status added
     * later is refused until somebody deliberately admits it, rather than
     * silently granting access the day it is introduced. The named cases keep
     * their specific wording so people are told what to do about it.
     *
     * The five statuses a user row can hold today are active, pending, inactive,
     * suspended and rejected.
     */
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

        if ($user->status === 'inactive') {
            throw new BusinessException('Your account has been deactivated. Contact your administrator.', 403);
        }

        // Anything that is not one of the five known values — including null on a
        // row written before the column had a default — is refused rather than
        // waved through.
        if ($user->status !== 'active') {
            Log::channel('auth')->warning('Login refused: unrecognised account status', [
                'user_id' => $user->id,
                'status'  => $user->status,
            ]);

            throw new BusinessException('Your account is not active. Contact your administrator.', 403);
        }

        if ($user->access_expires_at && $user->access_expires_at->isPast()) {
            throw new BusinessException('Your temporary access has expired. Contact your administrator.', 403);
        }
    }
}
