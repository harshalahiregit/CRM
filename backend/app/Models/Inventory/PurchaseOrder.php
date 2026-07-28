<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A purchase order raised on a vendor. */
class PurchaseOrder extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'inventory_purchase_orders';

    protected $fillable = [
        'tenant_id', 'code', 'description', 'vendor_id', 'warehouse_id', 'status', 'source',
        'type', 'tags', 'currency', 'order_date', 'expected_date', 'delivery_date',
        'subtotal', 'tax_total', 'discount_type', 'discount_mode', 'discount_value',
        'discount_amount', 'shipping_fee', 'total', 'notes', 'vendor_note', 'terms',
        'ship_address', 'ship_city', 'ship_state', 'ship_zip', 'ship_country',
        'created_by', 'approved_by', 'approved_at', 'sent_at',
    ];

    protected $casts = [
        'order_date'      => 'date',
        'expected_date'   => 'date',
        'delivery_date'   => 'date',
        'approved_at'     => 'datetime',
        'sent_at'         => 'datetime',
        'subtotal'        => 'decimal:2',
        'tax_total'       => 'decimal:2',
        'discount_value'  => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_fee'    => 'decimal:2',
        'total'           => 'decimal:2',
    ];

    public function vendor() { return $this->belongsTo(Vendor::class, 'vendor_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function lines() { return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }

    /** Sum of qty still to be received across all lines. */
    public function outstandingQty(): float
    {
        return (float) $this->lines->sum(fn ($l) => max(0, (float) $l->qty - (float) $l->received_qty));
    }
}
