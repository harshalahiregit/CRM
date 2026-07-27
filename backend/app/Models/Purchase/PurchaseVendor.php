<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchaseVendorStatus as Status;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Purchase Vendor — the Purchase module's OWN vendor master (purchase_vendors),
 * a completely independent business entity from the shared Vendor and from TPV.
 * All Purchase workflow tables reference purchase_vendor_id → this model.
 *
 * It is ALSO the Purchase vendor portal's own identity: an Authenticatable that
 * issues its own Sanctum tokens (tokenable = PurchaseVendor). The portal never
 * authenticates through the shared User / vendors / TPV auth.
 */
class PurchaseVendor extends Model implements AuthenticatableContract
{
    use Auditable, AuthenticatableTrait, BelongsToTenant, HasApiTokens, Notifiable, SoftDeletes;

    protected $table = 'purchase_vendors';

    protected $fillable = [
        'tenant_id', 'user_id', 'account_manager_id',
        'purchase_vendor_code', 'company_name', 'legal_name', 'vendor_type',
        'email', 'phone', 'website', 'category',
        'registration_number', 'gst_number', 'pan_number',
        // Vendor-master profile/financial fields (Purchase-owned)
        'balance', 'balance_as_of', 'currency', 'language',
        'bank_details', 'payment_terms', 'return_policy',
        'address', 'city', 'state', 'country', 'pincode',
        'status', 'approved_at', 'approved_by', 'notes',
        'access_token', 'access_expires_at',
        // Portal auth (Purchase-owned)
        'password', 'portal_status', 'email_verified_at', 'email_verification_token',
        'password_reset_token', 'password_reset_expires_at', 'last_login_at', 'last_login_ip',
    ];

    protected $casts = [
        'balance'                   => 'decimal:2',
        'balance_as_of'             => 'date',
        'approved_at'               => 'datetime',
        'access_expires_at'         => 'datetime',
        'email_verified_at'         => 'datetime',
        'password_reset_expires_at' => 'datetime',
        'last_login_at'             => 'datetime',
    ];

    /** Credentials/tokens are never disclosed in payloads. */
    protected $hidden = [
        'access_token', 'password', 'remember_token',
        'email_verification_token', 'password_reset_token',
    ];

    protected $appends = ['status_label'];

    /* ── Portal auth helpers ────────────────────────────────────────────── */

    public function isPortalActive(): bool
    {
        return $this->portal_status === 'active';
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markLoggedIn(?string $ip): void
    {
        $this->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->saveQuietly();
    }

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function accountManager()
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    public function contacts()
    {
        return $this->hasMany(PurchaseContact::class, 'purchase_vendor_id');
    }

    public function documents()
    {
        return $this->hasMany(PurchaseDocument::class, 'purchase_vendor_id');
    }

    public function onboarding()
    {
        return $this->hasOne(PurchaseOnboarding::class, 'purchase_vendor_id');
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function isActive(): bool
    {
        return $this->status === Status::ACTIVE;
    }

    /** May this vendor be transacted with (PR/PO/invoice/contract)? */
    public function isEngageable(): bool
    {
        return Status::isEngageable($this->status);
    }
}
