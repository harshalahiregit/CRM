<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One consignment on the road. See the migration for why in-transit stock lives
 * in a real transit warehouse rather than behind a flag.
 */
class Transfer extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'inventory_transfers';

    public const STATUSES = ['draft', 'in_transit', 'received', 'closed', 'cancelled'];

    /** Nothing may be dispatched or received once it is in one of these. */
    public const CLOSED = ['closed', 'cancelled'];

    protected $fillable = [
        'tenant_id', 'code', 'voucher_id', 'from_warehouse_id', 'to_warehouse_id', 'status',
        'dispatched_at', 'dispatched_by', 'expected_at', 'received_at', 'received_by', 'closed_at',
        'carrier', 'tracking_number', 'vehicle_no', 'driver_name', 'driver_phone',
        'note', 'created_by',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'received_at'   => 'datetime',
        'closed_at'     => 'datetime',
        'expected_at'   => 'date',
    ];

    public function lines()
    {
        return $this->hasMany(TransferLine::class, 'transfer_id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function dispatcher()
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    /** Late, and still on the road. The only definition of "overdue" that means anything. */
    public function isOverdue(): bool
    {
        return $this->status === 'in_transit'
            && $this->expected_at !== null
            && $this->expected_at->isPast();
    }
}
