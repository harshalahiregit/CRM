<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** A temperature/humidity reading taken at a warehouse, flagged in- or out-of-band. */
class WarehouseEnvReading extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_warehouse_env_readings';

    protected $fillable = [
        'tenant_id', 'warehouse_id', 'temperature', 'humidity',
        'in_band', 'note', 'recorded_by', 'recorded_at',
    ];

    protected $casts = [
        'temperature' => 'decimal:2',
        'humidity'    => 'decimal:2',
        'in_band'     => 'boolean',
        'recorded_at' => 'datetime',
    ];

    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
}
