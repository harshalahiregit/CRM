<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A recorded decision about a piece of dead stock, tracked through to done. */
class DeadStockAction extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'inventory_dead_stock_actions';

    public const ACTIONS = ['discount', 'liquidate', 'transfer', 'write_off', 'dismiss'];

    protected $fillable = [
        'tenant_id', 'product_id', 'action', 'status', 'qty', 'warehouse_id',
        'to_warehouse_id', 'discount_percent', 'new_price', 'value_snapshot',
        'applied', 'note', 'assigned_to', 'created_by', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'qty'              => 'decimal:3',
        'discount_percent' => 'decimal:2',
        'new_price'        => 'decimal:2',
        'value_snapshot'   => 'decimal:2',
        'applied'          => 'boolean',
        'resolved_at'      => 'datetime',
    ];

    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function toWarehouse() { return $this->belongsTo(Warehouse::class, 'to_warehouse_id'); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
