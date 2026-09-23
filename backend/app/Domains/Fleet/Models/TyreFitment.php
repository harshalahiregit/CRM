<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-MAINT — one tyre's life on one position.
 *
 * A tyre outlives the axle it sits on, so this is a CHAIN, not a mutable row:
 * fitting a tyre opens a record, removing it closes that record, and refitting
 * opens the next. A casing's whole history is every row sharing its `tyre_id`.
 *
 * That is what makes cost-per-kilometre answerable — the distance a casing has
 * run is the sum of its fitments, across whatever vehicles it has been on.
 */
class TyreFitment extends Model
{
    use BelongsToCompany;

    protected $table = 'tyre_fitments';

    /** Procured to scrap: in stock, fitted, removed, retreaded, scrapped. */
    public const IN_STOCK  = 'IN_STOCK';
    public const FITTED    = 'FITTED';
    public const REMOVED   = 'REMOVED';
    public const RETREADED = 'RETREADED';
    public const SCRAPPED  = 'SCRAPPED';

    public const STATUSES = [
        self::IN_STOCK, self::FITTED, self::REMOVED, self::RETREADED, self::SCRAPPED,
    ];

    /** The state in which a tyre is physically on a vehicle. */
    public const ON_VEHICLE = [self::FITTED];

    /** What removing a tyre may leave it as — anything but back on the truck. */
    public const OUTCOMES = [self::REMOVED, self::RETREADED, self::SCRAPPED, self::IN_STOCK];

    public const POSITIONS = [
        'front_left', 'front_right',
        'rear_inner_left', 'rear_outer_left',
        'rear_inner_right', 'rear_outer_right',
        'trailer_left', 'trailer_right',
        'spare',
    ];

    /** Below this the casing is legally and practically finished (mm). */
    public const MIN_TREAD_MM = 1.6;

    protected $fillable = [
        'company_id', 'tyre_id', 'vehicle_id', 'position', 'status',
        'tread_depth', 'odometer_at_fitment', 'odometer_at_removal',
        'fitted_on', 'removed_on', 'inspected_on', 'note',
    ];

    protected $casts = [
        'company_id'          => 'integer',
        'vehicle_id'          => 'integer',
        'tread_depth'         => 'decimal:2',
        'odometer_at_fitment' => 'decimal:1',
        'odometer_at_removal' => 'decimal:1',
        'fitted_on'           => 'date',
        'removed_on'          => 'date',
        'inspected_on'        => 'date',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** Kilometres this fitment has run, once it has been removed. */
    public function kilometresRun(): ?float
    {
        if ($this->odometer_at_fitment === null || $this->odometer_at_removal === null) {
            return null;
        }

        return round((float) $this->odometer_at_removal - (float) $this->odometer_at_fitment, 1);
    }

    public function isWornOut(): bool
    {
        return $this->tread_depth !== null && (float) $this->tread_depth <= self::MIN_TREAD_MM;
    }
}
