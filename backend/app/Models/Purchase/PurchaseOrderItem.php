<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_order_items';

    protected $fillable = [
        'tenant_id','purchase_order_id','catalog_item_id','description','qty','received_qty','unit','rate','tax','contract_rate_applied','amount','sort_order',
    ];

    protected $casts = [
        'qty'                   => 'decimal:2',
        'received_qty'          => 'decimal:2',
        'rate'                  => 'decimal:2',
        'tax'                   => 'decimal:2',
        'contract_rate_applied' => 'boolean',
        'amount'                => 'decimal:2',
    ];

    protected $appends = ['pending_qty'];

    protected static function booted(): void
    {
        // Line amount is always derived — never trusted from the client.
        static::saving(function (PurchaseOrderItem $item) {
            $base         = $item->qty * $item->rate;
            $item->amount = round($base + ($base * ($item->tax / 100)), 2);
        });
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** Ordered minus already received — the max a new GRN line may accept. */
    public function getPendingQtyAttribute()
    {
        return max(0, round((float) $this->qty - (float) $this->received_qty, 2));
    }
}
