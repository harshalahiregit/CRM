<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** An alternate pack unit for a product (Box=12, Carton=144, …). */
class ProductUnit extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_product_units';

    protected $fillable = ['tenant_id', 'product_id', 'name', 'factor', 'barcode', 'order'];

    protected $casts = ['factor' => 'decimal:6'];

    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
}
