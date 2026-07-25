<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Links a product to a vendor that supplies it (their SKU, price, MOQ, lead time). */
class ProductVendor extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_product_vendors';

    protected $fillable = [
        'tenant_id', 'product_id', 'vendor_id', 'vendor_sku',
        'price', 'moq', 'lead_time_days', 'is_preferred',
    ];

    protected $casts = [
        'price'          => 'decimal:2',
        'moq'            => 'decimal:3',
        'lead_time_days' => 'integer',
        'is_preferred'   => 'boolean',
    ];

    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
    public function vendor() { return $this->belongsTo(Vendor::class, 'vendor_id'); }
}
