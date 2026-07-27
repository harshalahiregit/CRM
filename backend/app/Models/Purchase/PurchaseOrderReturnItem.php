<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One returned line on a Purchase Order Return. `amount` is the line total net
 * of its discount and including tax; the parent's money columns are always
 * recomputed by PurchaseOrderReturn::recalculate().
 */
class PurchaseOrderReturnItem extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_order_return_items';

    protected $fillable = [
        'tenant_id', 'purchase_order_return_id', 'purchase_order_item_id',
        'description', 'qty', 'unit', 'rate', 'discount', 'tax', 'amount', 'sort_order',
    ];

    protected $casts = [
        'qty'      => 'decimal:3',
        'rate'     => 'decimal:2',
        'discount' => 'decimal:2',
        'tax'      => 'decimal:2',
        'amount'   => 'decimal:2',
    ];

    public function orderReturn()
    {
        return $this->belongsTo(PurchaseOrderReturn::class, 'purchase_order_return_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    /** Line total: (qty × rate − discount) + tax%. */
    public static function computeAmount(array $line): float
    {
        $gross = (float) ($line['qty'] ?? 0) * (float) ($line['rate'] ?? 0);
        $net   = $gross - min((float) ($line['discount'] ?? 0), $gross);

        return round($net + $net * ((float) ($line['tax'] ?? 0) / 100), 2);
    }
}
