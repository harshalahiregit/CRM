<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class VoucherItem extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_voucher_items';

    protected $fillable = [
        'tenant_id', 'voucher_id', 'product_id',
        'warehouse_id', 'from_warehouse_id', 'to_warehouse_id', 'location_id',
        'quantity', 'unit_price', 'tax_rate', 'discount', 'amount', 'available_qty',
        'lot_number', 'expiry_date', 'note',
        // Receiving inspection — see the migration for why these are three
        // separate numbers rather than one.
        'ordered_qty', 'received_qty', 'accepted_qty', 'rejected_qty',
        'qc_status', 'rejection_reason',
    ];

    /**
     * What actually goes on the shelf when this line posts.
     *
     * Falls back to `quantity` when nobody inspected, so every receipt written
     * before inspection existed keeps meaning exactly what it meant.
     */
    public function postableQuantity(): float
    {
        return $this->accepted_qty !== null ? (float) $this->accepted_qty : (float) $this->quantity;
    }

    protected $casts = [
        'quantity'      => 'decimal:3',
        'unit_price'    => 'decimal:2',
        'tax_rate'      => 'decimal:2',
        'discount'      => 'decimal:2',
        'amount'        => 'decimal:2',
        'available_qty' => 'decimal:3',
        'expiry_date'   => 'date',
        'ordered_qty'   => 'decimal:3',
        'received_qty'  => 'decimal:3',
        'accepted_qty'  => 'decimal:3',
        'rejected_qty'  => 'decimal:3',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }
}
