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

    /* ── The reefer genset, not the engine (T-06) ───────────────────── */

    public const GENSET_OFF     = 'OFF';
    public const GENSET_ON      = 'ON';
    public const GENSET_STANDBY = 'STANDBY';
    public const GENSET_FAULT   = 'FAULT';

    /**
     * What we STORE. Uppercase per spec 12.S11, and richer than the API
     * contract on purpose.
     *
     * STOS-API describes `ON | OFF | UNKNOWN`. Collapsing to that would throw
     * away the two states that matter most on a reefer: a genset in FAULT is
     * not the same fact as one somebody switched OFF, and STANDBY (running on
     * dock power) is not the same as running on its own engine. A load spoils
     * identically either way, but the person fixing it needs to know which.
     *
     * So the contract is honoured at the BOUNDARY and the detail is kept
     * behind it — see `normaliseGeneratorState()`.
     */
    public const GENERATOR_STATES = [
        self::GENSET_OFF, self::GENSET_ON, self::GENSET_STANDBY, self::GENSET_FAULT,
    ];

    /**
     * What we ACCEPT, and what each thing means once stored.
     *
     * Units already in the field send lowercase; refusing them to tidy a
     * vocabulary would stop live ingestion, which is never worth it. `UNKNOWN`
     * is the API's third value and maps to null — "the device did not say" is
     * its own state and must not be recorded as OFF, or a silent probe would
     * read as a genset somebody turned off.
     */
    public const GENERATOR_INPUT = [
        'off' => self::GENSET_OFF,          'OFF' => self::GENSET_OFF,
        'on' => self::GENSET_ON,            'ON' => self::GENSET_ON,
        'standby' => self::GENSET_STANDBY,  'STANDBY' => self::GENSET_STANDBY,
        'fault' => self::GENSET_FAULT,      'FAULT' => self::GENSET_FAULT,
        'unknown' => null,                  'UNKNOWN' => null,
    ];

    /**
     * States in which the genset is NOT cooling the load.
     *
     * FAULT belongs here and that is the point of keeping it: a faulted unit
     * reported as running is how a spoiled load goes unnoticed.
     */
    public const GENSET_NOT_COOLING = [self::GENSET_OFF, self::GENSET_FAULT];

    /** Whatever the device sent, in the vocabulary we store. Null if unsayable. */
    public static function normaliseGeneratorState(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::GENERATOR_INPUT[$value] ?? self::GENERATOR_INPUT[strtoupper(trim($value))] ?? null;
    }

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
