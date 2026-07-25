<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequestItem extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_request_items';

    protected $fillable = [
        'tenant_id','purchase_request_id','catalog_item_id','description','qty','unit','rate','tax','contract_rate_applied','amount','sort_order',
    ];

    protected $casts = [
        'qty'                   => 'decimal:2',
        'rate'                  => 'decimal:2',
        'tax'                   => 'decimal:2',
        'contract_rate_applied' => 'boolean',
        'amount'                => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Line amount is always derived — never trusted from the client.
        static::saving(function (PurchaseRequestItem $item) {
            $base         = $item->qty * $item->rate;
            $item->amount = round($base + ($base * ($item->tax / 100)), 2);
        });
    }

    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }
}
