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
    public const OWNERSHIPS = ['owned', 'leased', 'attached', 'market'];

    /**
     * T-01 — the vocabulary the D-62 union migration documents.
     *
     * Validated on input only, never on read: rows migrated from Operations
     * carry whatever that table held, and rejecting a stored value would make a
     * vehicle unopenable in the very screen you would use to correct it.
     */
    public const FUEL_TYPES = ['diesel', 'petrol', 'cng', 'lng', 'electric', 'hybrid'];
    /**
     * `in_operation` is set by dispatch through FleetResourceGateway (BRW-050)
     * when a trip departs, and cleared when it closes. Its absence was flagged
     * in PendingFleetResourceGateway as the thing blocking that handover.
     *
     * It is deliberately NOT settable from the vehicle form: like the workshop
     * states, it is a consequence of something happening elsewhere.
     */
    public const STATUS_IN_OPERATION = 'in_operation';

    /**
     * T-04 — `breakdown` is a state, `service due` deliberately is not.
     *
     * A truck stopped on the hard shoulder is not the same as one in a workshop
     * bay: the first means a load is stranded and somebody is arranging
     * recovery. Operations needs to tell them apart.
     *
     * Service-due is NOT here. A truck past its interval is still roadworthy,
     * and writing that into `status` would drop it out of allocation — a missed
     * oil change silently taking a truck off the road. It is derived by
     * ServiceScheduleEvaluator and warns instead.
     */
    public const STATUS_BREAKDOWN = 'breakdown';

    public const STATUSES   = ['active', 'in_operation', 'in_maintenance', 'breakdown', 'idle', 'retired'];

    /** States in which the vehicle is off the road and cannot be dispatched. */
    public const OFF_ROAD_STATES = ['in_maintenance', 'breakdown', 'retired'];
    public const COMPLIANCE = ['compliant', 'expiring', 'expired', 'blocked'];

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
