<?php

namespace App\Models\Purchase;

use App\Models\Inventory\Product;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchaseVendorItemStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Purchase Vendor ↔ Inventory Item mapping (purchase_vendor_items).
 *
 * This model owns the RELATIONSHIP only. Item facts (name, sku, group, unit)
 * always come from the joined Inventory product — they are never copied here, so
 * Inventory stays the single Item Master. Deleting a mapping removes the supply
 * link only; the Inventory product and every other vendor's mapping are
 * untouched.
 *
 * Purchase-owned: keyed by purchase_vendor_id → purchase_vendors. No shared
 * Vendor, no TPV.
 */
class PurchaseVendorItem extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_vendor_items';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'inventory_product_id',
        'effective_date', 'status', 'remarks',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
    ];

    protected $appends = ['status_label'];

    /* ── Relationships ──────────────────────────────────────────────────── */

    /** The Purchase-owned vendor side of the mapping. */
    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    /** The Inventory Item Master side — read-only reference, never duplicated. */
    public function product()
    {
        return $this->belongsTo(Product::class, 'inventory_product_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
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
}
