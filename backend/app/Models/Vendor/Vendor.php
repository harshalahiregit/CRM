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
    ];

    protected $casts = [
        'engagements' => 'array',
        'approved_at' => 'datetime',
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
