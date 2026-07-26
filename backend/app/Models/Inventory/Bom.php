<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A bill of materials — the recipe for building a finished-good product. */
class Bom extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'inventory_boms';

    protected $fillable = [
        'tenant_id', 'product_id', 'name', 'output_qty', 'status', 'note', 'created_by',
    ];

    protected $casts = ['output_qty' => 'decimal:3'];

    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
    public function lines() { return $this->hasMany(BomLine::class, 'bom_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
