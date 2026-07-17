<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'inventory_warehouses';

    /** Site types a warehouse can be — the UI turns these into labels. */
    public const TYPES = ['godown', 'store_room', 'cold_storage', 'open_yard', 'transit', 'virtual'];

    protected $fillable = [
        'tenant_id', 'name', 'code', 'type', 'order', 'address', 'city', 'state',
        'zip_code', 'country', 'note', 'manager_id', 'is_default', 'display', 'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        // Blueprint's "Display" toggle — hides a site from pickers without deleting it.
        'display'    => 'boolean',
        'order'      => 'integer',
    ];

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function locations()
    {
        return $this->hasMany(Location::class, 'warehouse_id');
    }

    public function stock()
    {
        return $this->hasMany(Stock::class, 'warehouse_id');
    }
}
