<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An item let out to a customer for a period, expected back. */
class Rental extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'inventory_rentals';

    public const STATUSES = ['reserved', 'out', 'returned', 'overdue', 'cancelled'];
    public const PERIODS = ['day', 'week', 'month'];

    protected $fillable = [
        'tenant_id', 'code', 'customer_name', 'customer_contact', 'product_id',
        'asset_id', 'item_label', 'warehouse_id', 'qty', 'rate', 'rate_period',
        'deposit', 'status', 'out_date', 'due_date', 'returned_date', 'charged',
        'note', 'created_by',
    ];

    protected $casts = [
        'qty'           => 'decimal:3',
        'rate'          => 'decimal:2',
        'deposit'       => 'decimal:2',
        'charged'       => 'decimal:2',
        'out_date'      => 'date',
        'due_date'      => 'date',
        'returned_date' => 'date',
    ];

    protected $appends = ['is_overdue'];

    public function product() { return $this->belongsTo(Product::class, 'product_id'); }
    public function asset() { return $this->belongsTo(Asset::class, 'asset_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    /** Out, past its due date, and not yet back. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'out'
            && $this->due_date
            && $this->due_date->isPast()
            && ! $this->returned_date;
    }
}
