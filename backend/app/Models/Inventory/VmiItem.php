<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One product a VMI agreement covers, with its min/max levels. */
class VmiItem extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_vmi_items';

    protected $fillable = ['tenant_id', 'agreement_id', 'product_id', 'min_level', 'max_level'];

    protected $casts = [
        'min_level' => 'decimal:3',
        'max_level' => 'decimal:3',
    ];

    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
    public function agreement() { return $this->belongsTo(VmiAgreement::class, 'agreement_id'); }
}
