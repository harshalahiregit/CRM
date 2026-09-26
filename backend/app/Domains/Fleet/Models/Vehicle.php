<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * STOS-FLEET — the vehicle master. Root of the Fleet domain.
 *
 * Holds what a vehicle IS. What it is DOING lives in VehicleLiveStatus (now)
 * and TelemetryRecord (history) — never here (golden rule 3).
 *
 * The status/type vocabularies are constants so the migration default, the form
 * requests and the frontend all validate against ONE list instead of three
 * copies of the same magic strings.
 */
class Vehicle extends Model
{
    use SoftDeletes;
    use BelongsToCompany;

    protected $table = 'vehicles';

    public const TYPES      = ['truck', 'trailer', 'tipper', 'tanker', 'reefer', 'lcv', 'other'];

    /**
     * T-03 — ownership, the full vocabulary of STOS-FLEET §10, UPPERCASE.
     *
     * The model shipped with four values — owned/leased/attached/market — and
     * §10 names six: Owned, Financed, Leased, Contracted, Attached, Other.
     * Two were simply missing, and their absence was not harmless: the masters
     * move (2027_01_02) had to FLATTEN what it could not store — FINANCED became
     * owned and CONTRACTED became attached — so a financed truck arrived looking
     * paid-for and a contracted one looking like an owner-driver's. §10 lists
     * ownership as what drives "asset cost; EMI; profitability; utilization",
     * and each of those reads the distinction the flattening destroyed.
     *
     * `market` is gone, and it folds into OTHER, not CONTRACTED. It was never
     * in §10 — it was the model's own invention — and no row has ever held it
     * (measured). Spot-market hire is a one-off, not a standing contract, so
     * calling it CONTRACTED would assert an agreement that does not exist;
     * OTHER, §10's own catch-all, is the honest home for "an arrangement
     * outside the five named ones".
     *
     * UPPERCASE because it is a database enum and 12.S11 rules those uppercase,
     * exactly as vehicle status and driver status already are. The API still
     * speaks the lowercase code through the label map.
     */
    public const OWNERSHIP_OWNED      = 'OWNED';
    public const OWNERSHIP_FINANCED   = 'FINANCED';
    public const OWNERSHIP_LEASED     = 'LEASED';
    public const OWNERSHIP_CONTRACTED = 'CONTRACTED';
    public const OWNERSHIP_ATTACHED   = 'ATTACHED';
    public const OWNERSHIP_OTHER      = 'OTHER';

    public const OWNERSHIPS = [
        self::OWNERSHIP_OWNED, self::OWNERSHIP_FINANCED, self::OWNERSHIP_LEASED,
        self::OWNERSHIP_CONTRACTED, self::OWNERSHIP_ATTACHED, self::OWNERSHIP_OTHER,
    ];

    public const OWNERSHIP_LABELS = [
        self::OWNERSHIP_OWNED      => 'Owned',
        self::OWNERSHIP_FINANCED   => 'Financed',
        self::OWNERSHIP_LEASED     => 'Leased',
        self::OWNERSHIP_CONTRACTED => 'Contracted',
        self::OWNERSHIP_ATTACHED   => 'Attached',
        self::OWNERSHIP_OTHER      => 'Other',
    ];

    /**
     * T-01 — the vocabulary the D-62 union migration documents.
     *
     * Validated on input only, never on read: rows migrated from Operations
     * carry whatever that table held, and rejecting a stored value would make a
     * vehicle unopenable in the very screen you would use to correct it.
     */
    public const FUEL_TYPES = ['diesel', 'petrol', 'cng', 'lng', 'electric', 'hybrid'];
    /**
     * ── SERVICE-DUE IS DELIBERATELY NOT A STATE ───────────────────────────
     * A truck past its service interval is still roadworthy. Writing that into
     * `status` would drop it out of allocation, so a missed oil change would
     * silently take a working truck off the road. It is derived by
     * ServiceScheduleEvaluator and warns instead — the same reason a driver's
     * expired licence blocks the driver rather than the truck.
     */
    /**
     * The vehicle asset state machine. Fleet is the sole authority for it.
     *
     * Ruled by the owner, 2026-09-19, closing T-02 and the vehicle half of
     * T-51. Uppercase because these strings cross a module boundary: Dev 1's
     * board and Dev 3's billing switch on them, and two spellings of one state
     * is how a condition gets tested for and silently never matches.
     */
    public const STATUS_AVAILABLE          = 'AVAILABLE';
    public const STATUS_ALLOCATED          = 'ALLOCATED';
    public const STATUS_IN_TRANSIT         = 'IN_TRANSIT';
    public const STATUS_UNDER_MAINTENANCE  = 'UNDER_MAINTENANCE';
    public const STATUS_COMPLIANCE_BLOCKED = 'COMPLIANCE_BLOCKED';
    public const STATUS_IDLE               = 'IDLE';
    public const STATUS_BREAKDOWN          = 'BREAKDOWN';
    public const STATUS_RETIRED            = 'RETIRED';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_ALLOCATED,
        self::STATUS_IN_TRANSIT,
        self::STATUS_UNDER_MAINTENANCE,
        self::STATUS_COMPLIANCE_BLOCKED,
        self::STATUS_IDLE,
        self::STATUS_BREAKDOWN,
        self::STATUS_RETIRED,
    ];

    /**
     * Out on a trip: assigned, or actually moving.
     *
     * Both mean "not available for another load", but they are not the same
     * fact — an ALLOCATED truck can still be swapped, an IN_TRANSIT one is a
     * recovery problem.
     */
    public const ON_TRIP_STATES = [self::STATUS_ALLOCATED, self::STATUS_IN_TRANSIT];

    /**
     * States a vehicle may hold and still be considered for a trip (PLN-002).
     *
     * The Fleet-side twin of `Support\Transport\VehicleStatus::ALLOCATABLE`,
     * which is `['available','idle']` and therefore matches nothing on this
     * table. Person 1's allocation reader points here when it swaps onto the
     * Fleet master, so neither side has to carry a literal — the coupling he
     * named as (a) in D-100 is a constant rather than a rename.
     *
     * Being allocatable is not the same as being eligible: a vehicle in one of
     * these states can still be refused by a blocker. This narrows the query;
     * `VehicleAllocationService::blockersFor()` decides.
     */
    public const ALLOCATABLE = [self::STATUS_AVAILABLE, self::STATUS_IDLE];

    /**
     * The km/l this truck is judged against — T-19.
     *
     * Its own figure when somebody has measured it, the type default when
     * nobody has. Returned with its SOURCE, because an exception note that
     * says "below benchmark" without saying whose benchmark is an argument
     * waiting to happen: a driver disputing a flag needs to know whether the
     * number came from this vehicle's history or from a table of averages.
     *
     * @return array{value: float, source: string}
     */
    public function fuelBenchmark(): array
    {
        if ($this->benchmark_kmpl !== null && (float) $this->benchmark_kmpl > 0) {
            return ['value' => (float) $this->benchmark_kmpl, 'source' => 'vehicle'];
        }

        $byType = (float) (config('stos.fuel.benchmark_kmpl')[$this->vehicle_type] ?? 0);

        return ['value' => $byType, 'source' => 'type'];
    }

    /** States in which the vehicle is off the road and cannot be dispatched. */
    public const OFF_ROAD_STATES = [
        self::STATUS_UNDER_MAINTENANCE,
        self::STATUS_BREAKDOWN,
        self::STATUS_COMPLIANCE_BLOCKED,
        self::STATUS_RETIRED,
    ];

    /**
     * States a person may set by hand.
     *
     * The rest are applied by the thing that owns the fact: job cards apply
     * UNDER_MAINTENANCE and BREAKDOWN, dispatch applies ALLOCATED and
     * IN_TRANSIT, and the compliance sweep applies COMPLIANCE_BLOCKED. Letting
     * those be typed would put a truck back on the road without the check that
     * took it off.
     */
    public const MANUALLY_SETTABLE = [self::STATUS_AVAILABLE, self::STATUS_IDLE, self::STATUS_RETIRED];
    public const COMPLIANCE = ['compliant', 'expiring', 'expired', 'blocked'];

    /**
     * D-141 — the normalised plate is derived, never supplied. Same disease
     * P1 found in the licence: `registration_normalized` was added by the D-62
     * union with an index over it, but nothing wrote it on save, so it sat
     * empty and the plate search had to normalise every row at query time to
     * work around it (their D-153). A column shaped like a guard that is not
     * one.
     *
     * Written on every save so the index always sees the same shape, whichever
     * path wrote the row — the service, a seeder, the masters move or tinker.
     * `registration_normalized` is out of `$fillable` for the same reason
     * `licence_normalized` is on the driver: a caller setting it by hand could
     * put a value under the index that no plate normalises to.
     */
    protected static function booted(): void
    {
        static::saving(function (self $vehicle) {
            $vehicle->registration_normalized = filled($vehicle->registration_number)
                ? self::normaliseRegistration((string) $vehicle->registration_number)
                : null;
        });
    }

    /**
     * One plate, one form. "MH 12 AB 1234", "MH-12-AB-1234" and "mh12ab1234"
     * are one truck, so the unique index and the search mean something. Shared
     * so the model and `VehicleService` cannot drift to two definitions of
     * "the same plate".
     */
    public static function normaliseRegistration(string $plate): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($plate))) ?? '';
    }

    protected $fillable = [
        'company_id',
        'registration_number',
        // T-01 — the identity columns the D-62 union brought over from
        // Operations. They were on the table but unreachable: nothing could set
        // them, so every vehicle onboarded through Fleet came out blank, and
        // `capacity_tonnes` blank means the eligibility engine cannot match it
        // to an order's required payload (PLN-001).
        'fleet_number',
        'manufacturer',
        'model',
        'variant',
        'manufacturing_year',
        'purchase_date',
        'fuel_type',
        'branch',
        'capacity_tonnes',
        'benchmark_kmpl',
        'service_interval_km',
        'service_interval_days',
        'last_service_odometer',
        'last_service_on',
        'vehicle_type',
        'ownership_type',
        'chassis_number',
        'engine_number',
        'gps_device_id',
        'status',
        'compliance_status',
        'registration_expiry',
        'insurance_expiry',
        'fitness_expiry',
        'permit_expiry',
        'puc_expiry',
        'compliance_hold',
        'compliance_hold_reason',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'manufacturing_year' => 'integer',
        'purchase_date'      => 'date',
        'capacity_tonnes'    => 'decimal:2',
        'benchmark_kmpl'     => 'decimal:2',
        'service_interval_km'   => 'integer',
        'service_interval_days' => 'integer',
        'last_service_odometer' => 'decimal:1',
        'last_service_on'       => 'date',
        'registration_expiry' => 'date',
        'insurance_expiry'    => 'date',
        'fitness_expiry'      => 'date',
        'permit_expiry'       => 'date',
        'puc_expiry'          => 'date',
        'compliance_hold'     => 'boolean',
    ];

    /**
     * The statutory papers, and the label each is known by.
     *
     * Order is the order the compliance tab lists them. Every one of these is a
     * reason a vehicle is stopped at a checkpoint, and each has its own issuer
     * and its own renewal date -- which is why they are five columns and not
     * one rolled-up flag.
     */
    public const EXPIRY_DOCUMENTS = [
        'registration_expiry' => 'Registration (RC)',
        'insurance_expiry'    => 'Insurance',
        'fitness_expiry'      => 'Fitness certificate',
        'permit_expiry'       => 'Permit',
        'puc_expiry'          => 'Pollution (PUC)',
    ];

    /* ── Relationships ──────────────────────────────────────────── */

    /** The single fast-lookup row. hasOne, because tier 1 is one row per vehicle. */
    public function liveStatus()
    {
        return $this->hasOne(VehicleLiveStatus::class, 'vehicle_id');
    }

    public function telemetryRecords()
    {
        return $this->hasMany(TelemetryRecord::class, 'vehicle_id');
    }

    public function gensets()
    {
        return $this->hasMany(Genset::class, 'vehicle_id');
    }

    public function fuelTransactions()
    {
        return $this->hasMany(FuelTransaction::class, 'vehicle_id');
    }

    public function fastagTransactions()
    {
        return $this->hasMany(FastagTransaction::class, 'vehicle_id');
    }

    public function maintenanceJobs()
    {
        return $this->hasMany(MaintenanceJob::class, 'vehicle_id');
    }

    public function ureaTransactions()
    {
        return $this->hasMany(UreaTransaction::class, 'vehicle_id');
    }

    public function tyreFitments()
    {
        return $this->hasMany(TyreFitment::class, 'vehicle_id');
    }
}
