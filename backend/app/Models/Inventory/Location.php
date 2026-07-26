<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A node in a warehouse's storage tree: zone → rack → shelf → bin → position.
 * Self-nesting, so a site only models the depth it actually uses.
 */
class Location extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_locations';

    public const TYPES = ['zone', 'rack', 'shelf', 'bin', 'position'];

    /** What a location's `capacity` number actually counts. See the migration. */
    public const CAPACITY_UOMS = ['units', 'volume', 'weight'];

    protected $fillable = ['tenant_id', 'warehouse_id', 'parent_id', 'name', 'code', 'type', 'capacity', 'capacity_uom'];

    protected $casts = ['capacity' => 'decimal:3'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
