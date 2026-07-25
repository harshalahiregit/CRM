<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PurchaseQuotationItem extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_quotation_items';

    protected $fillable = [
        'tenant_id','purchase_quotation_id','purchase_rfq_item_id','catalog_item_id',
        'description','qty','unit','rate','tax','amount','sort_order',
    ];

    protected $casts = [
        'qty'    => 'decimal:2',
        'rate'   => 'decimal:2',
        'tax'    => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Line amount is always derived — never trusted from the client.
        static::saving(function (PurchaseQuotationItem $item) {
            $base         = $item->qty * $item->rate;
            $item->amount = round($base + ($base * ($item->tax / 100)), 2);
        });
    }

    public function quotation()
    {
        return $this->belongsTo(PurchaseQuotation::class, 'purchase_quotation_id');
    }

    /** The RFQ line this quote line answers. */
    public function rfqItem()
    {
        return $this->belongsTo(PurchaseRfqItem::class, 'purchase_rfq_item_id');
    }
}
