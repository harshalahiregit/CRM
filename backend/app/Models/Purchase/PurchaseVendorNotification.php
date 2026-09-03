<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A single bell notification for one Purchase vendor. Mirror of
 * App\Models\Notification, keyed to purchase_vendors (the Purchase portal's own
 * identity) rather than users.
 */
class PurchaseVendorNotification extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'type', 'title', 'message', 'link', 'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    protected $appends = ['is_read'];

    public function getIsReadAttribute(): bool
    {
        return $this->read_at !== null;
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeForVendor($query, int $purchaseVendorId)
    {
        return $query->where('purchase_vendor_id', $purchaseVendorId);
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }
}
