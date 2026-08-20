<?php

namespace App\Models\Vendor;

use App\Models\Tpv\TpvOnboarding;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Tpv\TpvRegistrationType as RegistrationType;
use App\Support\Vendor\VendorStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unified vendor master, shared by TPV (onboarding/HSSE) and Purchase
 * (procurement). `engagements` records which modules a vendor participates in.
 */
class Vendor extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'vendors';

    protected $fillable = [
        // purchase_vendor_id is the OPTIONAL link to this company's Purchase
        // record, set by an explicit admin action — see linkPurchaseVendor().
        'tenant_id','user_id','purchase_vendor_id','account_manager_id','vendor_code','company_name','legal_name',
        'vendor_type','registration_type','engagements','email','phone','website','category',
        'registration_number','gst_number','pan_number',
        'address','city','state','country','pincode',
        'status','approved_at','approved_by','notes',
        // Vendor Risk Classification (gap report area 2) — forward-looking risk
        // tier, distinct from the VRS performance band.
        'risk_level','risk_score','risk_factors','risk_notes','risk_assessed_at','risk_assessed_by',
        // Prequalification (gap report area 6) — scored questionnaire outcome.
        'qualification_status','qualification_score','qualification_responses',
        'qualification_notes','qualification_assessed_at','qualification_assessed_by',
        'auto_suspended','suspended_at','suspension_reason',
        'offboarded_at','offboarding_reason',
        // Temporary TPV access (Phase 2)
        'is_temporary','access_start_at','access_expires_at','access_status',
        'access_extended_at','access_extended_by','extension_reason',
        'converted_to_permanent_at','converted_by','temporary_created_by','validity_days','access_reminders_sent',
    ];

    protected $casts = [
        'engagements'               => 'array',
        'approved_at'               => 'datetime',
        'risk_factors'              => 'array',
        'risk_score'                => 'integer',
        'risk_assessed_at'          => 'datetime',
        'qualification_responses'   => 'array',
        'qualification_score'       => 'integer',
        'qualification_assessed_at' => 'datetime',
        'auto_suspended'            => 'boolean',
        'suspended_at'              => 'datetime',
        'offboarded_at'             => 'datetime',
        'is_temporary'              => 'boolean',
        'access_start_at'           => 'datetime',
        'access_expires_at'         => 'datetime',
        'access_extended_at'        => 'datetime',
        'converted_to_permanent_at' => 'datetime',
        'validity_days'             => 'integer',
        'access_reminders_sent'     => 'array',
        'first_login_at'            => 'datetime',
        'last_login_at'             => 'datetime',
        'login_count'               => 'integer',
        'welcome_banner_dismissed_at' => 'datetime',
    ];

    protected $appends = ['status_label', 'registration_type_label', 'validity_countdown'];

    /* ── Code auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (Vendor $vendor) {
            if (empty($vendor->vendor_code)) {
                $year  = date('Y');
                $count = static::withTrashed()
                               ->where('tenant_id', $vendor->tenant_id)
                               ->whereYear('created_at', $year)
                               ->count() + 1;
                $vendor->vendor_code = 'VEN-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /** Who last classified this vendor's risk (gap report area 2). */
    public function riskAssessor()
    {
        return $this->belongsTo(User::class, 'risk_assessed_by');
    }

    /** Who last prequalified this vendor (gap report area 6). */
    public function qualificationAssessor()
    {
        return $this->belongsTo(User::class, 'qualification_assessed_by');
    }

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function contacts()
    {
        return $this->hasMany(VendorContact::class, 'vendor_id');
    }

    /** Normalized bank account, mirrored from the TPV onboarding profile. */
    public function bankAccount()
    {
        return $this->hasOne(VendorBankAccount::class, 'vendor_id');
    }

    /** The primary contact, per the is_primary flag. */
    public function primaryContact()
    {
        return $this->hasOne(VendorContact::class, 'vendor_id')->where('is_primary', true);
    }

    public function documents()
    {
        return $this->hasMany(VendorDocument::class, 'vendor_id');
    }

    /** Customers directly linked to this vendor (clients.vendor_id). */
    public function customers()
    {
        return $this->hasMany(\App\Models\Customer\Client::class, 'vendor_id');
    }

    /** The TPV onboarding workflow over this vendor, if engaged for TPV. */
    public function tpvOnboarding()
    {
        return $this->hasOne(TpvOnboarding::class, 'vendor_id');
    }

    /** The Purchase onboarding workflow over this vendor, if engaged for Purchase. */
    public function purchaseOnboarding()
    {
        return $this->hasOne(\App\Models\Purchase\PurchaseOnboarding::class, 'vendor_id');
    }

    /** The portal login (role = vendor | third_party_vendor). */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * This company's Purchase record, when it is registered in both modules.
     *
     * Null for the ordinary case of a vendor that is only TPV. Commercial
     * documents live entirely on the Purchase side and are read through this
     * link — nothing is copied across.
     */
    public function purchaseVendor()
    {
        return $this->belongsTo(\App\Models\Purchase\PurchaseVendor::class, 'purchase_vendor_id');
    }

    /** Internal staff who owns this vendor account. */
    public function accountManager()
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class, 'tenant_id');
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    /**
     * The registration type this TPV was created with, as a display label.
     * Falls back to Long-Term TPV for rows predating the column.
     */
    public function getRegistrationTypeLabelAttribute(): string
    {
        return RegistrationType::label($this->registration_type);
    }

    /**
     * Remaining-validity payload for the UI countdown.
     *
     * Reuses this model's EXISTING expiry logic — isTemporary(),
     * access_expires_at and isAccessExpired() (which already honours a
     * force-expired or converted access_status). No second calculation is
     * introduced; the generic helper only formats those facts.
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
     * Has the temporary window actually begun? TPV's own answer: the vendor must
     * be Active AND carry an activation timestamp — access_start_at (stamped by
     * the TPV access service) or, failing that, approved_at. An Inactive/Draft
     * vendor has not started its clock; it is awaiting activation, not expired.
     * Never derived from created_at.
     */
    public function isAccessStarted(): bool
    {
        return $this->status === Status::ACTIVE
            && ($this->access_start_at !== null || $this->approved_at !== null);
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

    public function isEngageable(): bool
    {
        return Status::isEngageable($this->status);
    }

    public function hasEngagement(string $engagement): bool
    {
        return in_array($engagement, $this->engagements ?? [], true);
    }

    /* ── Temporary access helpers (Phase 2) ─────────────────────────────── */

    public function isTemporary(): bool
    {
        // registration_type is the stored choice and the source of truth, exactly as
        // PurchaseVendor::isTemporary() treats it — the two modules must answer this
        // question the same way. The legacy signals remain as a fallback for rows
        // written before registration_type existed: this used to read is_temporary
        // alone, which is unset on every row created by the admin/self-registration
        // paths, so temporary TPVs never got a countdown and never expired.
        if ($this->registration_type) {
            return $this->registration_type === \App\Support\Tpv\TpvRegistrationType::TEMPORARY;
        }

        return (bool) $this->is_temporary || $this->vendor_type === 'temporary';
    }

    /**
     * Query counterpart of isTemporary() — the single source of truth for "which
     * rows are temporary". Every listing/sweep must use this instead of a raw
     * `where('is_temporary', true)`: that boolean is unset on every vendor created
     * through the admin/self-registration paths, so filtering on it hid all of them
     * (empty Temporary tab, no expiry reminders). Mirrors the predicate exactly:
     * registration_type when present, else the legacy is_temporary/vendor_type.
     */
    public function scopeTemporary($query)
    {
        $temp = \App\Support\Tpv\TpvRegistrationType::TEMPORARY;

        return $query->where(function ($q) use ($temp) {
            $q->where('registration_type', $temp)
                ->orWhere(fn ($w) => $w
                    ->whereNull('registration_type')
                    ->where(fn ($x) => $x->where('is_temporary', true)->orWhere('vendor_type', 'temporary')));
        });
    }

    /** Server-authoritative seconds remaining (never negative). */
    public function accessSecondsRemaining(): int
    {
        if (! $this->access_expires_at) {
            return 0;
        }

        return (int) max(0, $this->access_expires_at->getTimestamp() - now()->getTimestamp());
    }

    public function accessBand(): string
    {
        return \App\Support\Tpv\TpvAccessStatus::band($this->accessSecondsRemaining());
    }

    public function isAccessExpired(): bool
    {
        if (! $this->isTemporary() || ! $this->access_expires_at || $this->access_status === \App\Support\Tpv\TpvAccessStatus::CONVERTED) {
            return false;
        }

        // Either force-expired (status) or the clock has run out.
        return $this->access_status === \App\Support\Tpv\TpvAccessStatus::EXPIRED
            || $this->accessSecondsRemaining() <= 0;
    }

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    public function scopeEngageable($query)
    {
        return $query->whereIn('status', Status::ENGAGEABLE);
    }

    public function scopeForEngagement($query, string $engagement)
    {
        return $query->whereJsonContains('engagements', $engagement);
    }
}
