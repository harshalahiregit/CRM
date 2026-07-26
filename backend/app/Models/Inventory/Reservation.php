<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A claim on stock that hasn't shipped yet. Active reservations are what make
 * "available" smaller than "on hand" — the difference between what's physically
 * there and what you're actually free to sell.
 */
class Reservation extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_reservations';

    public const FOR = ['customer', 'project', 'sales_order', 'production'];
    public const STATUSES = ['active', 'released', 'fulfilled'];

    protected $fillable = [
        'tenant_id', 'product_id', 'warehouse_id', 'quantity', 'reserved_for',
        'reference_id', 'reference_label', 'priority', 'status', 'expires_at',
        'note', 'created_by', 'released_by', 'released_at',
    ];

    protected $casts = [
        'quantity'    => 'decimal:3',
        'priority'    => 'integer',
        'expires_at'  => 'date',
        'released_at' => 'datetime',
    ];

    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeActive($q) { return $q->where('status', 'active'); }
}
