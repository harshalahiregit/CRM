<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vendor-level PPE stock / allocation (§17). Records the kit a vendor holds for
 * its own workforce — how much is allocated to the vendor vs. already issued out
 * to workers — distinct from the warehouse Inventory ledger. The optional
 * inventory_item_id links a row to an Inventory product where one exists.
 */
class TpvVendorPpeStock extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_vendor_ppe_stocks';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'inventory_item_id', 'item', 'project',
        'allocated_qty', 'issued_qty', 'notes',
    ];

    protected $casts = [
        'allocated_qty' => 'decimal:3',
        'issued_qty'    => 'decimal:3',
    ];

    protected $appends = ['available_qty'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Models\Inventory\Product::class, 'inventory_item_id');
    }

    /** Still on the vendor's shelf: allocated but not yet issued to a worker. */
    public function getAvailableQtyAttribute(): float
    {
        return round((float) $this->allocated_qty - (float) $this->issued_qty, 3);
    }
}
