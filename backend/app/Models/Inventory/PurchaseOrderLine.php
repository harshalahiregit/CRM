<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One item line on a purchase order. */
class PurchaseOrderLine extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_purchase_order_lines';

    protected $fillable = [
        'tenant_id', 'purchase_order_id', 'product_id', 'description',
        'qty', 'received_qty', 'unit_price', 'tax_rate', 'discount_pct', 'line_total',
    ];

    protected $casts = [
        'qty'          => 'decimal:3',
        'received_qty' => 'decimal:3',
        'unit_price'   => 'decimal:2',
        'tax_rate'     => 'decimal:2',
        'discount_pct' => 'decimal:2',
        'line_total'   => 'decimal:2',
    ];

    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
    public function order() { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
}
