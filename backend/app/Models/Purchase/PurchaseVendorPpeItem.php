<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One item on a Purchase vendor's OWN PPE list.
 *
 * Purchase-owned twin of TpvVendorPpeItem. The vendor's stock, not the
 * company's: quantity lives on this row and never enters the Inventory ledger.
 */
class PurchaseVendorPpeItem extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_vendor_ppe_items';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'name', 'category', 'size', 'spec', 'unit',
        'qty_in_stock', 'image_path', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'qty_in_stock' => 'float',
        'is_active'    => 'boolean',
    ];

    /** The private file path is never sent; the UI asks the image route instead. */
    protected $hidden = ['image_path'];

    protected $appends = ['has_image'];

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function getHasImageAttribute(): bool
    {
        return (bool) $this->image_path;
    }
}
