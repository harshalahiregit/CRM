<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-INT — tier 1 telemetry: where a vehicle is RIGHT NOW (golden rule 3).
 *
 * One row per vehicle, overwritten on every ping, read by the live map. The
 * primary key IS `vehicle_id`, so Eloquent needs telling three things: the key
 * name, that it does not auto-increment, and that there are no timestamps —
 * `last_ping_at` is the only clock that matters here.
 *
 * Write it with updateOrCreate() keyed on vehicle_id; never insert() into it,
 * or a vehicle ends up with a second "current" position.
 */
class VehicleLiveStatus extends Model
{
    use BelongsToCompany;

    protected $table = 'vehicle_live_status';

    protected $primaryKey = 'vehicle_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    /** off | on | standby | fault — the reefer genset, not the engine. */
    public const GENERATOR_STATES = ['off', 'on', 'standby', 'fault'];

    protected $fillable = [
        'vehicle_id',
        'company_id',
        'latitude',
        'longitude',
        'speed',
        'ignition',
        'generator_status',
        'temperature',
        'last_ping_at',
    ];

    protected $casts = [
        'company_id'   => 'integer',
        'vehicle_id'   => 'integer',
        // Kept as fixed-precision strings by the DB (golden rule 5); cast for
        // arithmetic without ever letting a float near the stored value.
        'latitude'     => 'decimal:8',
        'longitude'    => 'decimal:8',
        'speed'        => 'decimal:2',
        'temperature'  => 'decimal:2',
        'ignition'     => 'boolean',
        'last_ping_at' => 'datetime',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
