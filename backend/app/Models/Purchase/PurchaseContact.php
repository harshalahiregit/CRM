<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use App\Support\Purchase\PurchaseContactStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A contact person belonging to a Purchase vendor — the Purchase module's OWN
 * contact record (purchase_contacts), independent of TPV. Keyed to the shared
 * Vendor Master by purchase_vendor_id only. At most one primary per vendor (enforced in
 * PurchaseContactService).
 */
class PurchaseContact extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_contacts';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'created_by', 'updated_by',
        'first_name', 'last_name', 'designation', 'department',
        'email', 'phone', 'mobile', 'alternate_mobile',
        'address', 'city', 'state', 'country', 'pincode', 'notes',
        'is_primary', 'status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    protected $appends = ['full_name', 'status_label'];

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function isActive(): bool
    {
        return $this->status === Status::ACTIVE;
    }
}
