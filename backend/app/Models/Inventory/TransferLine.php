<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One product on one consignment.
 *
 * `received_qty` being null means nobody at the far end has looked yet — which
 * is a different fact from "none of it arrived", exactly as on a count sheet.
 */
class TransferLine extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_transfer_lines';

    public const STATUSES = ['in_transit', 'received', 'short', 'written_off'];

    protected $fillable = [
        'tenant_id', 'transfer_id', 'product_id',
        'dispatched_qty', 'received_qty', 'lost_qty',
        'from_location_id', 'to_location_id', 'status', 'note',
    ];

    protected $casts = [
        'dispatched_qty' => 'decimal:3',
        'received_qty'   => 'decimal:3',
        'lost_qty'       => 'decimal:3',
    ];

    public function transfer()
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * What is still genuinely on the road: dispatched, minus what arrived,
     * minus what was deliberately written off. This is the number that must
     * equal the transit warehouse's balance — the module's one cross-check that
     * nothing bypassed the flow.
     */
    public function outstanding(): float
    {
        return round((float) $this->dispatched_qty - (float) ($this->received_qty ?? 0) - (float) $this->lost_qty, 3);
    }
}
