<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing to walk to and count: a product, optionally in a specific bin.
 *
 * `counted_qty` being null is meaningful — it is the difference between "I went
 * and there were none" (0) and "nobody has been yet" (null). Collapsing the two
 * would let an unwalked session be approved as if the shelf were empty.
 */
class CountLine extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_count_lines';

    public const STATUSES = ['pending', 'matched', 'variance', 'recounted'];

    protected $fillable = [
        'tenant_id', 'count_session_id', 'product_id', 'location_id',
        'system_qty', 'system_at_count', 'counted_qty', 'recount_qty', 'variance',
        'status', 'counted_by', 'counted_at',
        'photo_path', 'gps_lat', 'gps_lng', 'gps_accuracy', 'device',
        'movement_id', 'note',
    ];

    protected $casts = [
        'system_qty'      => 'decimal:3',
        'system_at_count' => 'decimal:3',
        'counted_qty'     => 'decimal:3',
        'recount_qty'     => 'decimal:3',
        'variance'        => 'decimal:3',
        'gps_lat'         => 'decimal:7',
        'gps_lng'         => 'decimal:7',
        'gps_accuracy'    => 'decimal:2',
        'counted_at'      => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(CountSession::class, 'count_session_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function counter()
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /**
     * The figure that will be posted: a recount overrides the first count,
     * because the recount is the one somebody went back and checked.
     */
    public function finalQuantity(): ?float
    {
        if ($this->recount_qty !== null) {
            return (float) $this->recount_qty;
        }

        return $this->counted_qty !== null ? (float) $this->counted_qty : null;
    }

    public function isCounted(): bool
    {
        return $this->counted_qty !== null;
    }
}
