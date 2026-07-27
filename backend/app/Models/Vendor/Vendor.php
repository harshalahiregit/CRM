<?php

namespace App\Models\Vendor;

use App\Models\Tpv\TpvOnboarding;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
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
        'tenant_id','user_id','account_manager_id','vendor_code','company_name','legal_name',
        'vendor_type','engagements','email','phone','website','category',
        'registration_number','gst_number','pan_number',
        'address','city','state','country','pincode',
        'status','approved_at','approved_by','notes',
        // Temporary TPV access (Phase 2)
        'is_temporary','access_start_at','access_expires_at','access_status',
        'access_extended_at','access_extended_by','extension_reason',
        'converted_to_permanent_at','converted_by','temporary_created_by','validity_days','access_reminders_sent',
    ];

    protected $casts = [
        'engagements'               => 'array',
        'approved_at'               => 'datetime',
        'is_temporary'              => 'boolean',
        'access_start_at'           => 'datetime',
        'access_expires_at'         => 'datetime',
        'access_extended_at'        => 'datetime',
        'converted_to_permanent_at' => 'datetime',
        'validity_days'             => 'integer',
        'access_reminders_sent'     => 'array',
    ];

    protected $appends = ['status_label'];

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
        return (bool) $this->is_temporary;
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
