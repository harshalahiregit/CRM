<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Support\Purchase\PurchaseRegistrationType as RegistrationType;
use App\Support\Purchase\PurchaseVendorStatus as Status;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Purchase Vendor Portal authentication — completely independent of the shared
 * User / vendors / TPV auth. Registers, logs in, verifies, resets and provisions
 * a PurchaseVendor identity directly against purchase_vendors, issuing Sanctum
 * tokens whose tokenable is the PurchaseVendor. Reuses only generic infra (Hash,
 * Sanctum, logging).
 */
class PurchaseVendorPortalAuthService
{
    private const TOKEN_NAME = 'purchase-portal';

    /** Public self-registration — creates ONLY a PurchaseVendor (never a User/Vendor). */
    public function register(array $data): PurchaseVendor
    {
        $existing = PurchaseVendor::where('tenant_id', $data['tenant_id'])->where('email', $data['email'])->first();
        if ($existing) {
            throw new BusinessException('An account with this email already exists.', 422);
        }

        $vendor = PurchaseVendor::create([
            'tenant_id'                => $data['tenant_id'],
            // One shared generator for every creation path — no duplicate codes.
            'purchase_vendor_code'     => app(PurchaseVendorService::class)->nextVendorCode((int) $data['tenant_id']),
            'company_name'             => $data['company_name'],
            'email'                    => $data['email'],
            'phone'                    => $data['phone'] ?? null,
            'password'                 => Hash::make($data['password']),
            // Store the registration choice verbatim so it survives approval,
            // activation, login and onboarding without ever being re-derived.
            'vendor_type'              => ($data['registration_type'] ?? null) === RegistrationType::TEMPORARY ? 'temporary' : 'standard',
            'registration_type'        => RegistrationType::normalize($data['registration_type'] ?? null),
            'status'                   => Status::DRAFT,
            'portal_status'            => 'inactive',
            'email_verification_token' => Str::random(48),
        ]);

        $vendor->recordAudit('Purchase Vendor Registered', null, null, ['email' => $vendor->email]);
        // Delivery is a generic notification concern; logged here (no shared auth).
        Log::channel('purchase')->info('Purchase vendor registered — verification pending', [
            'purchase_vendor_id' => $vendor->id, 'email' => $vendor->email,
            'verify_token' => $vendor->email_verification_token,
        ]);

        return $vendor;
    }

    /** Confirm the email and activate the portal account. */
    public function verifyEmail(string $token): PurchaseVendor
    {
        $vendor = PurchaseVendor::where('email_verification_token', $token)->first();
        if (! $vendor) {
            throw new BusinessException('This verification link is not valid or has already been used.', 404);
        }

        $vendor->forceFill([
            'email_verified_at'        => now(),
            'email_verification_token' => null,
            'portal_status'            => 'active',
        ])->save();
        $vendor->recordAudit('Purchase Vendor Email Verified', null, null, []);

        return $vendor;
    }

    /** Authenticate against purchase_vendors and issue a Sanctum token. */
    public function login(string $email, string $password, ?string $ip): array
    {
        $candidates = PurchaseVendor::where('email', $email)->get();
        $vendor = $candidates->first(fn (PurchaseVendor $v) => $v->password && Hash::check($password, $v->password));

        if (! $vendor) {
            Log::channel('purchase')->warning('Purchase portal login failed', ['email' => $email]);
            throw new BusinessException('Invalid credentials. Please check your email and password.', 401);
        }
        if ($vendor->portal_status === 'suspended') {
            throw new BusinessException('Your portal account is suspended. Contact the procurement team.', 403);
        }
        if ($vendor->portal_status !== 'active') {
            throw new BusinessException('Please verify your email before signing in.', 403);
        }

        $token = $vendor->createToken(self::TOKEN_NAME)->plainTextToken;
        $vendor->markLoggedIn($ip);
        $vendor->recordAudit('Purchase Vendor Signed In', null, null, ['ip' => $ip]);
        Log::channel('purchase')->info('Purchase vendor logged in', [
            'purchase_vendor_id' => $vendor->id, 'tenant_id' => $vendor->tenant_id,
        ]);

        return ['access_token' => $token, 'token_type' => 'Bearer', 'vendor' => $vendor->fresh()];
    }

    public function logout(PurchaseVendor $vendor): void
    {
        $token = $vendor->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        $vendor->recordAudit('Purchase Vendor Signed Out', null, null, []);
    }

    /** Issue a password-reset token (delivery is logged; no shared reset table). */
    public function forgotPassword(string $email): void
    {
        $vendor = PurchaseVendor::where('email', $email)->first();
        // Do not reveal whether the email exists.
        if (! $vendor) {
            return;
        }

        $vendor->forceFill([
            'password_reset_token'       => Str::random(48),
            'password_reset_expires_at'  => now()->addHour(),
        ])->save();

        Log::channel('purchase')->info('Purchase vendor password reset requested', [
            'purchase_vendor_id' => $vendor->id, 'reset_token' => $vendor->password_reset_token,
        ]);
    }

    public function resetPassword(string $email, string $token, string $password): PurchaseVendor
    {
        $vendor = PurchaseVendor::where('email', $email)
            ->where('password_reset_token', $token)
            ->where('password_reset_expires_at', '>', now())
            ->first();

        if (! $vendor) {
            throw new BusinessException('This reset link is invalid or has expired.', 422);
        }

        $vendor->forceFill([
            'password'                  => Hash::make($password),
            'password_reset_token'      => null,
            'password_reset_expires_at' => null,
        ])->save();
        // Sign out everywhere on a password change.
        $vendor->tokens()->delete();
        $vendor->recordAudit('Purchase Vendor Password Reset', null, null, []);

        return $vendor;
    }

    /**
     * Provision (or re-activate) a portal account on admin approval/activation.
     * Generates a one-time password if none is set and returns it for delivery.
     */
    public function provision(PurchaseVendor $vendor, User $actor): ?string
    {
        $plain = null;
        $patch = ['portal_status' => 'active', 'email_verified_at' => $vendor->email_verified_at ?? now()];

        if (! $vendor->password) {
            $plain = Str::password(12);
            $patch['password'] = Hash::make($plain);
        }

        $vendor->forceFill($patch)->save();
        $vendor->recordAudit('Purchase Vendor Portal Account Provisioned', $actor, null, ['email' => $vendor->email]);
        Log::channel('purchase')->info('Purchase vendor portal provisioned', [
            'purchase_vendor_id' => $vendor->id, 'credentials_sent' => (bool) $plain,
        ]);

        return $plain;
    }
}
