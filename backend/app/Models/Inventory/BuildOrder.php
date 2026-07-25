<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A run of a BOM for a target quantity of finished goods. */
class BuildOrder extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'inventory_build_orders';

    public const STATUSES = ['draft', 'in_progress', 'completed', 'cancelled'];

    protected $fillable = [
        'tenant_id', 'code', 'bom_id', 'product_id', 'warehouse_id', 'qty',
        'status', 'note', 'created_by', 'completed_by', 'completed_at',
    ];

    protected $casts = [
        'qty'          => 'decimal:3',
        'completed_at' => 'datetime',
    ];

    public function bom() { return $this->belongsTo(Bom::class, 'bom_id'); }
    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
