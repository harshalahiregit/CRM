<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchaseRegistrationType as RegistrationType;
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
        'purchase_vendor_code', 'company_name', 'legal_name', 'vendor_type', 'registration_type',
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
        // Risk Score (Purchase-native, admin-set)
        'risk_level', 'risk_score', 'risk_notes', 'risk_assessed_at',
        // Prequalification (Purchase-native scored questionnaire, mirrors TPV)
        'qualification_status', 'qualification_score', 'qualification_responses',
        'qualification_notes', 'qualified_at', 'qualified_by',
    ];

    protected $casts = [
        'balance'                   => 'decimal:2',
        'balance_as_of'             => 'date',
        'approved_at'               => 'datetime',
        'access_expires_at'         => 'datetime',
        'email_verified_at'         => 'datetime',
        'password_reset_expires_at' => 'datetime',
        'last_login_at'             => 'datetime',
        'first_login_at'            => 'datetime',
        'login_count'               => 'integer',
        'welcome_banner_dismissed_at' => 'datetime',
        'risk_assessed_at'          => 'datetime',
        'risk_score'                => 'integer',
        'qualification_responses'   => 'array',
        'qualification_score'       => 'integer',
        'qualified_at'              => 'datetime',
    ];

    /** Credentials/tokens are never disclosed in payloads. */
    protected $hidden = [
        'access_token', 'password', 'remember_token',
        'email_verification_token', 'password_reset_token',
    ];

    protected $appends = ['status_label', 'registration_type_label', 'validity_countdown'];

    /* ── Portal auth helpers ────────────────────────────────────────────── */

    public function isPortalActive(): bool
    {
        return $this->portal_status === 'active';
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Record a successful portal sign-in. first_login_at is stamped once and
     * never overwritten, so "has this vendor ever signed in?" stays answerable.
     */
    public function markLoggedIn(?string $ip): void
    {
        $this->forceFill([
            'last_login_at'  => now(),
            'last_login_ip'  => $ip,
            'first_login_at' => $this->first_login_at ?? now(),
            'login_count'    => (int) ($this->login_count ?? 0) + 1,
        ])->saveQuietly();
    }

    /** Show the post-activation welcome banner until the vendor dismisses it. */
    public function shouldShowWelcomeBanner(): bool
    {
        return $this->status === Status::ACTIVE && $this->welcome_banner_dismissed_at === null;
    }

    public function dismissWelcomeBanner(): void
    {
        $this->forceFill(['welcome_banner_dismissed_at' => now()])->saveQuietly();
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

    /** Who last prequalified this vendor (Purchase-native scored questionnaire). */
    public function qualificationAssessor()
    {
        return $this->belongsTo(User::class, 'qualified_by');
    }

    /** The Purchase-side due-diligence checklist for this vendor (one per vendor). */
    public function dueDiligence()
    {
        return $this->hasOne(PurchaseDueDiligence::class, 'purchase_vendor_id');
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

    /**
     * Customers directly linked to this Purchase vendor (clients.purchase_vendor_id).
     * The Purchase-side mirror of Vendor::customers(); its own link column, so a
     * client can belong to a TPV vendor, a Purchase vendor, both or neither.
     */
    public function customers()
    {
        return $this->hasMany(\App\Models\Customer\Client::class, 'purchase_vendor_id');
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    /**
     * The registration type this vendor was created with, as a display label.
     * Falls back to Standard Vendor for rows predating the column.
     */
    public function getRegistrationTypeLabelAttribute(): string
    {
        return RegistrationType::label($this->registration_type);
    }

    public function isActive(): bool
    {
        return $this->status === Status::ACTIVE;
    }

    /**
     * Is this a time-boxed account? Purchase's own answer, taken from the stored
     * registration type (with the legacy vendor_type as a fallback for rows that
     * predate it). Nothing here consults TPV.
     */
    public function isTemporary(): bool
    {
        return $this->registration_type === RegistrationType::TEMPORARY
            || (! $this->registration_type && $this->vendor_type === 'temporary');
    }

    /** Has a temporary account's access window closed? Purchase-owned. */
    public function isAccessExpired(): bool
    {
        if (! $this->isTemporary() || ! $this->access_expires_at) {
            return false;
        }

        return $this->access_expires_at->getTimestamp() <= now()->getTimestamp();
    }

    /**
     * Remaining-validity payload for the UI countdown. Built from this model's
     * own access_expires_at; the generic helper only formats it.
     */
    public function getValidityCountdownAttribute(): array
    {
        return \App\Support\ValidityCountdown::build(
            $this->isTemporary(),
            $this->access_expires_at,
            $this->isTemporary() ? $this->isAccessExpired() : null,
            $this->isAccessStarted(),
        );
    }

    /**
     * Has the temporary window actually begun? Purchase's own answer: the
     * account must be Active AND carry an activation timestamp (approved_at).
     * A Draft/Registered vendor has not started its clock — it is awaiting
     * activation, not expired. Never derived from created_at.
     */
    public function isAccessStarted(): bool
    {
        return $this->status === Status::ACTIVE && $this->approved_at !== null;
    }

    /** May this vendor be transacted with (PR/PO/invoice/contract)? */
    public function isEngageable(): bool
    {
        return Status::isEngageable($this->status);
    }
}
