<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One locked line on a rate contract. The `rate` is the pre-negotiated price a
 * PO can pull; min_qty/max_qty bound the band it applies over (both optional).
 */
class PurchaseContractItem extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_contract_items';

    protected $fillable = [
        'tenant_id','purchase_contract_id','catalog_item_id','description','unit','rate','tax','min_qty','max_qty','sort_order',
    ];

    protected $casts = [
        'rate'    => 'decimal:2',
        'tax'     => 'decimal:2',
        'min_qty' => 'decimal:2',
        'max_qty' => 'decimal:2',
    ];

    public function contract()
    {
        return $this->belongsTo(PurchaseContract::class, 'purchase_contract_id');
    }
}
