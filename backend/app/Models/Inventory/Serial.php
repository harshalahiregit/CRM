<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** One physically identifiable unit, tracked through its whole life. */
class Serial extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_serials';

    public const STATUSES = ['in_stock', 'issued', 'returned', 'scrapped'];

    protected $fillable = [
        'tenant_id', 'product_id', 'batch_id', 'warehouse_id', 'serial_no',
        'status', 'warranty_until', 'customer_ref', 'note', 'created_by',
    ];

    protected $casts = ['warranty_until' => 'date'];

    protected $appends = ['under_warranty'];

    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
    public function batch() { return $this->belongsTo(Batch::class, 'batch_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function events() { return $this->hasMany(SerialEvent::class, 'serial_id'); }

    public function getUnderWarrantyAttribute(): ?bool
    {
        return $this->warranty_until ? $this->warranty_until->isFuture() : null;
    }
}
