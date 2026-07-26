<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One component a BOM consumes, with its per-batch quantity. */
class BomLine extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_bom_lines';

    protected $fillable = ['tenant_id', 'bom_id', 'component_id', 'qty', 'note'];

    protected $casts = ['qty' => 'decimal:3'];

    public function component() { return $this->belongsTo(Product::class, 'component_id'); }
    public function bom() { return $this->belongsTo(Bom::class, 'bom_id'); }
}
