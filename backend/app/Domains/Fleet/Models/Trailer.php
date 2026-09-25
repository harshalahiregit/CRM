<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * STOS-FLEET — a trailer, which is a master and not a vehicle type (T-54).
 *
 * See the migration for why it is not a `vehicles` row. The short version is
 * that a trailer has no engine, so no fuel, no odometer and — the one that
 * would bite — no PUC certificate, which a compliance sweep would otherwise
 * demand of it forever.
 */
class Trailer extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $table = 'trailers';

    /* ── The asset state machine ────────────────────────────────────── */

    public const STATUS_AVAILABLE          = 'AVAILABLE';
    public const STATUS_COUPLED            = 'COUPLED';
    public const STATUS_UNDER_MAINTENANCE  = 'UNDER_MAINTENANCE';
    public const STATUS_COMPLIANCE_BLOCKED = 'COMPLIANCE_BLOCKED';
    public const STATUS_RETIRED            = 'RETIRED';

    public const STATUSES = [
        self::STATUS_AVAILABLE, self::STATUS_COUPLED, self::STATUS_UNDER_MAINTENANCE,
        self::STATUS_COMPLIANCE_BLOCKED, self::STATUS_RETIRED,
    ];

    /**
     * States a person may set by hand.
     *
     * COUPLED is written by coupling and cleared by uncoupling — typing it
     * would claim a pairing that does not exist, and the uncouple that should
     * clear it would never come. COMPLIANCE_BLOCKED is derived from the dates,
     * exactly as it is on a vehicle.
     */
    public const MANUALLY_SETTABLE = [
        self::STATUS_AVAILABLE, self::STATUS_UNDER_MAINTENANCE, self::STATUS_RETIRED,
    ];

    /** States in which a trailer may be put under a tractor. */
    public const COUPLABLE = [self::STATUS_AVAILABLE];

    public const TYPES = [
        'flatbed', 'skeletal', 'tipper', 'tanker', 'reefer', 'curtain', 'lowbed', 'other',
    ];

    /** Same vocabulary as a vehicle's, because it is the same question. */
    public const OWNERSHIPS = ['OWNED', 'LEASED', 'ATTACHED', 'MARKET'];

    /**
     * The papers that gate a trailer, and what each one is called.
     *
     * FOUR, where a vehicle has five. There is no PUC because there is no
     * engine to emit anything — and a sweep that asks for one would block a
     * legal trailer for a certificate that cannot be obtained.
     */
    public const EXPIRY_DOCUMENTS = [
        'registration_expiry' => 'Registration',
        'fitness_expiry'      => 'Fitness certificate',
        'insurance_expiry'    => 'Insurance',
        'permit_expiry'       => 'Permit',
    ];

    protected $fillable = [
        'company_id', 'trailer_number', 'registration_normalized', 'fleet_number',
        'trailer_type', 'ownership_type', 'capacity_tonnes', 'axles', 'length_feet',
        'manufacturer', 'model', 'manufacturing_year', 'purchase_date', 'chassis_number',
        'status', 'compliance_status',
        'registration_expiry', 'fitness_expiry', 'insurance_expiry', 'permit_expiry',
        'note', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'company_id'          => 'integer',
        'capacity_tonnes'     => 'decimal:2',
        'axles'               => 'integer',
        'length_feet'         => 'decimal:1',
        'manufacturing_year'  => 'integer',
        'purchase_date'       => 'date',
        'registration_expiry' => 'date',
        'fitness_expiry'      => 'date',
        'insurance_expiry'    => 'date',
        'permit_expiry'       => 'date',
    ];

    /** Plates are written "MH 12 AB 1234" as often as "MH12AB1234". */
    public static function normalise(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value)) ?? '';
    }

    protected static function booted(): void
    {
        static::saving(function (Trailer $trailer) {
            // Derived, never accepted from a caller — a normalised value that
            // disagrees with the number beside it is a duplicate waiting to be
            // created.
            $trailer->registration_normalized = self::normalise($trailer->trailer_number);
        });
    }

    /** The open coupling, if this trailer is under a tractor right now. */
    public function activeCoupling()
    {
        return $this->hasOne(VehicleTrailerAssignment::class, 'trailer_id')
            ->whereNull('uncoupled_at');
    }

    public function couplings()
    {
        return $this->hasMany(VehicleTrailerAssignment::class, 'trailer_id');
    }
}
