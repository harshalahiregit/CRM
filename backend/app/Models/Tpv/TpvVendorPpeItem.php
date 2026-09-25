<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;

/**
 * One item on a TPV vendor's OWN PPE list.
 *
 * The vendor's stock, not the company's: quantity lives on this row and never
 * enters the Inventory ledger. Issuing one to a worker decrements it; a genuine
 * return puts it back. Purchase keeps the same list in purchase_vendor_ppe_items.
 */
class TpvVendorPpeItem extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_vendor_ppe_items';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'name', 'category', 'size', 'spec', 'unit',
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
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getHasImageAttribute(): bool
    {
        return (bool) $this->image_path;
    }
}
