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
    public const STATUSES   = ['active', 'in_maintenance', 'idle', 'retired'];
    public const COMPLIANCE = ['compliant', 'expiring', 'expired', 'blocked'];

    protected $fillable = [
        'company_id',
        'registration_number',
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
