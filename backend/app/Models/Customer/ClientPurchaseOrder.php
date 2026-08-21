<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §17 COMMERCIAL — a purchase order the CUSTOMER issued to us.
 *
 * Not to be confused with the Purchase module's purchase_orders, which are the
 * orders we place with vendors. They are opposite ends of the same word: this
 * one is a customer's authority for us to bill, and finance quotes its number
 * on every invoice raised against it.
 *
 * `consumed` is stored rather than summed live because Customer must not query
 * Sales' invoice tables directly (§6). The service layer refreshes it through
 * the same seam the Overview uses.
 */
class ClientPurchaseOrder extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUSES = ['Open', 'Partially Billed', 'Exhausted', 'Closed', 'Cancelled'];

    /** Still available to bill against. */
    public const LIVE_STATUSES = ['Open', 'Partially Billed'];

    protected $fillable = [
        'tenant_id', 'client_id', 'po_number', 'po_date', 'valid_until', 'currency',
        'value', 'consumed', 'status', 'contract_id', 'scope', 'notes', 'created_by',
    ];

    protected $casts = [
        'po_date'     => 'date',
        'valid_until' => 'date',
        'value'       => 'decimal:2',
        'consumed'    => 'decimal:2',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /** Never negative: over-billing is a data problem, not negative headroom. */
    public function getRemainingAttribute(): float
    {
        return max(0, round((float) $this->value - (float) $this->consumed, 2));
    }

    public function getConsumedPercentAttribute(): float
    {
        return (float) $this->value > 0
            ? round(((float) $this->consumed / (float) $this->value) * 100, 1)
            : 0.0;
    }

    public function scopeLive($query)
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    /**
     * Expired while still having headroom.
     *
     * The combination is what matters — an expired PO with nothing left on it is
     * simply finished, but an expired PO with value remaining means work may be
     * being done with no authority to bill for it.
     */
    public function scopeLapsedWithHeadroom($query)
    {
        return $query->live()
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->whereColumn('consumed', '<', 'value');
    }
}
