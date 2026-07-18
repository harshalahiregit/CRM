<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A line the RFQ asks vendors to quote on. Carries no committed price — only an
 * optional target_rate as an internal budget estimate.
 */
class PurchaseRfqItem extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_rfq_items';

    protected $fillable = [
        'tenant_id','purchase_rfq_id','catalog_item_id','description','qty','unit','target_rate','tax','sort_order',
    ];

    protected $casts = [
        'qty'         => 'decimal:2',
        'target_rate' => 'decimal:2',
        'tax'         => 'decimal:2',
    ];

    public function rfq()
    {
        return $this->belongsTo(PurchaseRfq::class, 'purchase_rfq_id');
    }

    /** The catalog item this line was picked from, if any (soft link). */
    public function catalogItem()
    {
        return $this->belongsTo(PurchaseCatalogItem::class, 'catalog_item_id');
    }

    /** Every vendor's quote line answering this RFQ line — powers comparison. */
    public function quotationItems()
    {
        return $this->hasMany(PurchaseQuotationItem::class, 'purchase_rfq_item_id');
    }
}
