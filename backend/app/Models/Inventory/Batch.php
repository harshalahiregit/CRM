<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A received batch/lot of one product. `remaining_qty` is what's still on the
 * shelf from this batch, which is what FEFO picking consumes and what the
 * expiry dashboard reports on.
 */
class Batch extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_batches';

    /** Only a 'passed' batch may be issued — the rest are held back deliberately. */
    public const QUALITY = ['pending', 'passed', 'failed', 'quarantine'];

    protected $fillable = [
        'tenant_id', 'product_id', 'warehouse_id', 'batch_no', 'lot_number', 'vendor_batch_no',
        'manufactured_at', 'expiry_date', 'received_qty', 'remaining_qty', 'cost_price',
        'quality_status', 'expiry_alerted_at', 'note', 'created_by',
    ];

    protected $casts = [
        'manufactured_at'   => 'date',
        'expiry_date'       => 'date',
        'expiry_alerted_at' => 'datetime',
        'received_qty'    => 'decimal:3',
        'remaining_qty'   => 'decimal:3',
        'cost_price'      => 'decimal:2',
    ];

    protected $appends = ['days_to_expiry', 'is_expired'];

    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    /** Negative once past expiry, null when the batch never expires. */
    public function getDaysToExpiryAttribute(): ?int
    {
        return $this->expiry_date ? (int) now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false) : null;
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast() && (float) $this->remaining_qty > 0;
    }
}
