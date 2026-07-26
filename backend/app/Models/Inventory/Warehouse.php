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
        'custom_fields',
        'temp_min', 'temp_max', 'humidity_min', 'humidity_max',
        'track_environment', 'require_move_gps', 'require_move_photo',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        // Blueprint's "Display" toggle — hides a site from pickers without deleting it.
        'display'    => 'boolean',
        'order'      => 'integer',
        'custom_fields' => 'array',
        'temp_min'      => 'decimal:2',
        'temp_max'      => 'decimal:2',
        'humidity_min'  => 'decimal:2',
        'humidity_max'  => 'decimal:2',
        'track_environment'  => 'boolean',
        'require_move_gps'   => 'boolean',
        'require_move_photo' => 'boolean',
    ];

    /** Cold stores are environment-sensitive by their nature. */
    public function isColdChain(): bool
    {
        return $this->type === 'cold_storage';
    }

    /** Is a temperature/humidity reading inside this site's configured band? */
    public function readingInBand(?float $temp, ?float $humidity): bool
    {
        if ($temp !== null && $this->temp_min !== null && $temp < (float) $this->temp_min) {
            return false;
        }
        if ($temp !== null && $this->temp_max !== null && $temp > (float) $this->temp_max) {
            return false;
        }
        if ($humidity !== null && $this->humidity_min !== null && $humidity < (float) $this->humidity_min) {
            return false;
        }
        if ($humidity !== null && $this->humidity_max !== null && $humidity > (float) $this->humidity_max) {
            return false;
        }

        return true;
    }

    public function envReadings()
    {
        return $this->hasMany(WarehouseEnvReading::class, 'warehouse_id');
    }

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
