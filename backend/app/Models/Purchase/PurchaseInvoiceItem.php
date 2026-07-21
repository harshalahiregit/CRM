<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PurchaseInvoiceItem extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_invoice_items';

    protected $fillable = [
        'tenant_id','purchase_invoice_id','purchase_order_item_id','description','qty','unit','rate','tax','amount','sort_order',
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
        static::saving(function (PurchaseInvoiceItem $item) {
            $base         = $item->qty * $item->rate;
            $item->amount = round($base + ($base * ($item->tax / 100)), 2);
        });
    }

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    /** The PO line this invoice line bills for — the anchor for 3-way match. */
    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
