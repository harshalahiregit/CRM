<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptItem extends Model
{
    use BelongsToTenant;

    protected $table = 'goods_receipt_items';

    protected $fillable = [
        'tenant_id','goods_receipt_id','purchase_order_item_id','description',
        'ordered_qty','accepted_qty','rejected_qty','remarks',
    ];

    protected $casts = [
        'ordered_qty'  => 'decimal:2',
        'accepted_qty' => 'decimal:2',
        'rejected_qty' => 'decimal:2',
    ];

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
