<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Current balance of one product at one place. Never written directly by a
 * controller — StockService applies every change alongside a Movement row so the
 * number on screen always has history behind it.
 */
class Stock extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_stock';

    protected $fillable = ['tenant_id', 'product_id', 'warehouse_id', 'location_id', 'quantity', 'reserved_quantity'];

    protected $casts = [
        'quantity'          => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
    ];

    /** What can actually be promised to someone else. */
    public function getAvailableAttribute(): float
    {
        return (float) $this->quantity - (float) $this->reserved_quantity;
    }

    protected $appends = ['available'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
