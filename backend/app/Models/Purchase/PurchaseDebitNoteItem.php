<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PurchaseDebitNoteItem extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_debit_note_items';

    protected $fillable = [
        'tenant_id','purchase_debit_note_id','purchase_order_item_id','description','qty','unit','rate','tax','amount','sort_order',
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
        static::saving(function (PurchaseDebitNoteItem $item) {
            $base         = $item->qty * $item->rate;
            $item->amount = round($base + ($base * ($item->tax / 100)), 2);
        });
    }

    public function debitNote()
    {
        return $this->belongsTo(PurchaseDebitNote::class, 'purchase_debit_note_id');
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
