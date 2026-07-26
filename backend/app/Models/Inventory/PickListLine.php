<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One item on a pick list. Three quantities, because they can and do differ:
 * what the order needs, what the picker actually found, and what the packer
 * confirmed into the carton.
 */
class PickListLine extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_pick_list_lines';

    protected $fillable = [
        'tenant_id', 'pick_list_id', 'product_id',
        'required_qty', 'picked_qty', 'packed_qty',
        'location_id', 'batch_id', 'reservation_id', 'note',
    ];

    protected $casts = [
        'required_qty' => 'decimal:3',
        'picked_qty'   => 'decimal:3',
        'packed_qty'   => 'decimal:3',
    ];

    protected $appends = ['is_short'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function batch()
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    /** Picked less than the order needs — the customer gets a short delivery. */
    public function getIsShortAttribute(): bool
    {
        return (float) $this->picked_qty < (float) $this->required_qty;
    }
}
