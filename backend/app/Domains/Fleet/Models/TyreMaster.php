<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * STOS-MAINT — the casing as an asset (T-36).
 *
 * A tyre outlives the vehicle it is fitted to; that is the whole economics of
 * retreading. Modelling it as a string on a fitment row could answer "what is
 * on this axle" and nothing about what the casing has cost or where it is.
 */
class TyreMaster extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $table = 'tyre_masters';

    public const IN_STOCK  = 'IN_STOCK';
    public const FITTED    = 'FITTED';
    public const RETREADED = 'RETREADED';
    public const SCRAPPED  = 'SCRAPPED';

    public const STATUSES = [self::IN_STOCK, self::FITTED, self::RETREADED, self::SCRAPPED];

    /**
     * States a casing can be fitted from.
     *
     * RETREADED is included and that is the point of the state: a casing back
     * from the retreader is stock again, and one that has to be re-registered
     * to go back on a truck would simply be registered twice.
     */
    public const FITTABLE = [self::IN_STOCK, self::RETREADED];

    /**
     * Set by hand, as opposed to derived from what happens to it.
     *
     * FITTED is written by fitting and cleared by removal; RETREADED by the
     * retread action. Typing either would claim an event that never happened.
     */
    public const MANUALLY_SETTABLE = [self::IN_STOCK, self::SCRAPPED];

    protected $fillable = [
        'company_id', 'serial_number', 'brand', 'size', 'pattern',
        'purchase_cost', 'purchase_date', 'supplier', 'status',
        'retread_count', 'retread_cost_total',
        'new_tread_depth', 'scrap_tread_depth',
        'scrapped_on', 'scrap_reason', 'note', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'company_id'         => 'integer',
        'purchase_cost'      => 'decimal:2',
        'retread_cost_total' => 'decimal:2',
        'purchase_date'      => 'date',
        'scrapped_on'        => 'date',
        'retread_count'      => 'integer',
        'new_tread_depth'    => 'decimal:2',
        'scrap_tread_depth'  => 'decimal:2',
    ];

    public function fitments()
    {
        return $this->hasMany(TyreFitment::class, 'tyre_master_id');
    }

    /** Where it is right now, if it is on something. */
    public function activeFitment()
    {
        return $this->hasOne(TyreFitment::class, 'tyre_master_id')
            ->whereIn('status', TyreFitment::ON_VEHICLE);
    }

    /**
     * Everything this casing has cost so far — T-36.
     *
     * Purchase plus every retread. A casing on its third life has cost three
     * times, and judging it on the sticker price alone flatters retreading
     * exactly when somebody is deciding whether to do it again.
     */
    public function getLifetimeCostAttribute(): ?float
    {
        if ($this->purchase_cost === null) {
            return null;
        }

        return round((float) $this->purchase_cost + (float) $this->retread_cost_total, 2);
    }
}
