<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-INT — tier 2 telemetry: the append-only history (golden rule 3).
 *
 * Every ping, kept forever, never updated. Trip replay, temperature audit and
 * "where was it at 14:20" are answered from here — and nothing on a live screen
 * touches it, because this is the table that grows without bound.
 *
 * UPDATED_AT is switched off: there is no update to stamp on an append-only log.
 * `created_at` is our receipt time and `recorded_at` is the device's own clock;
 * they diverge whenever a unit buffers offline and replays, which is exactly
 * when someone asks.
 */
class TelemetryRecord extends Model
{
    use BelongsToCompany;

    protected $table = 'telemetry_records';

    /** Append-only: created_at is stamped, updated_at does not exist. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'vehicle_id',
        'device_id',
        'latitude',
        'longitude',
        'speed',
        'ignition',
        'generator_status',
        'temperature',
        'recorded_at',
    ];

    protected $casts = [
        'company_id'   => 'integer',
        'vehicle_id'   => 'integer',
        'latitude'     => 'decimal:8',
        'longitude'    => 'decimal:8',
        'speed'        => 'decimal:2',
        'temperature'  => 'decimal:2',
        'ignition'     => 'boolean',
        'recorded_at'  => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
