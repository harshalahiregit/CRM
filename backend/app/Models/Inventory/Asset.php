<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A tracked company asset (equipment/tool), distinct from sellable stock. */
class Asset extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'inventory_assets';

    public const STATUSES = ['in_service', 'maintenance', 'idle', 'reserved', 'damaged', 'retired', 'lost'];

    protected $fillable = [
        'tenant_id', 'code', 'name', 'category', 'product_id', 'serial_no',
        'status', 'assigned_to', 'assigned_employee_id', 'condition',
        'warehouse_id', 'location', 'purchase_date',
        'purchase_cost', 'warranty_until', 'next_service_due', 'note', 'created_by',
    ];

    protected $casts = [
        'purchase_date'    => 'date',
        'warranty_until'   => 'date',
        'next_service_due' => 'date',
        'purchase_cost'    => 'decimal:2',
    ];

    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function employee() { return $this->belongsTo(\App\Models\Hr\HrEmployee::class, 'assigned_employee_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
    public function events() { return $this->hasMany(AssetEvent::class, 'asset_id'); }
}
