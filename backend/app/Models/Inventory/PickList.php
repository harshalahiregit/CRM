<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One physical parcel from shelf to doorstep. See the migration for why pick,
 * pack and ship share a row rather than living in three tables.
 */
class PickList extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'inventory_pick_lists';

    /** The stages, in order. Anything not here is not a legal status. */
    public const STATUSES = ['open', 'picking', 'picked', 'packed', 'shipped', 'delivered', 'cancelled'];

    /** Stages where the parcel has left the building — nothing may be edited. */
    public const CLOSED = ['shipped', 'delivered', 'cancelled'];

    protected $fillable = [
        'tenant_id', 'code', 'voucher_id', 'warehouse_id', 'status', 'assigned_to',
        'picked_at', 'packed_at', 'shipped_at', 'delivered_at',
        'carrier', 'tracking_number', 'tracking_url', 'parcel_weight', 'parcel_count',
        'received_by', 'note', 'created_by',
    ];

    protected $casts = [
        'picked_at'     => 'datetime',
        'packed_at'     => 'datetime',
        'shipped_at'    => 'datetime',
        'delivered_at'  => 'datetime',
        'parcel_weight' => 'decimal:3',
        'parcel_count'  => 'integer',
    ];

    public function lines()
    {
        return $this->hasMany(PickListLine::class, 'pick_list_id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function picker()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }
}
