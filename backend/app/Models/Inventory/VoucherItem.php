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
        'quantity', 'unit_price', 'tax_rate', 'amount', 'available_qty',
        'lot_number', 'expiry_date', 'note',
    ];

    protected $casts = [
        'quantity'      => 'decimal:3',
        'unit_price'    => 'decimal:2',
        'tax_rate'      => 'decimal:2',
        'amount'        => 'decimal:2',
        'available_qty' => 'decimal:3',
        'expiry_date'   => 'date',
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
