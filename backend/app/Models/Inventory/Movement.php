<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable line in the stock ledger — the "why" behind every balance change.
 * Append-only: a movement is a historical fact, so it is never updated (hence
 * UPDATED_AT = null). Corrections are made by writing an opposing movement.
 */
class Movement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'inventory_movements';

    /** Movement reasons and the direction each implies. */
    public const TYPES = [
        'opening'    => 'in',
        'receive'    => 'in',
        'return'     => 'in',
        'issue'      => 'out',
        'damage'     => 'out',
        'expired'    => 'out',
        'lost'       => 'out',
        'scrap'      => 'out',
        'adjustment' => 'in',      // direction is decided by the sign the caller passes
        'transfer'   => 'transfer',
    ];

    protected $fillable = [
        'tenant_id', 'product_id', 'type', 'direction', 'quantity',
        'from_warehouse_id', 'to_warehouse_id', 'from_location_id', 'to_location_id',
        'balance_after', 'batch_no', 'serial_no', 'reason', 'notes',
        'reference_type', 'reference_id', 'actor_id', 'created_at',
    ];

    protected $casts = [
        'quantity'      => 'decimal:3',
        'balance_after' => 'decimal:3',
        'created_at'    => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
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
