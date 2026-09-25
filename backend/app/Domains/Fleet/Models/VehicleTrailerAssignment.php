<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-FLEET — one tractor under one trailer, for a period (T-54).
 *
 * An event with a start and an end, not a column on either side. A
 * `vehicles.trailer_id` would answer "which one is under it now", which is the
 * least useful version of the question people actually ask: *which trailer was
 * under that truck on the 14th*, asked when a load spoils, a claim is filed, or
 * a tyre fails.
 *
 * `active_trailer_id` / `active_vehicle_id` are STORED generated columns that
 * go null on uncoupling, with a unique index each. They are the backstop; the
 * service refuses first and with a sentence. The index is what holds when two
 * people couple the same trailer from two screens in the same second.
 */
class VehicleTrailerAssignment extends Model
{
    use BelongsToCompany;

    protected $table = 'vehicle_trailer_assignments';

    protected $fillable = [
        'company_id', 'vehicle_id', 'trailer_id',
        'coupled_at', 'uncoupled_at', 'coupled_by', 'uncoupled_by', 'reason',
    ];

    protected $casts = [
        'company_id'   => 'integer',
        'vehicle_id'   => 'integer',
        'trailer_id'   => 'integer',
        'coupled_at'   => 'datetime',
        'uncoupled_at' => 'datetime',
    ];

    /**
     * Generated columns are read-only in the database.
     *
     * Listed so nothing can mass-assign them and so a `fresh()` reads what the
     * engine computed rather than what PHP guessed.
     */
    protected $guarded = ['active_trailer_id', 'active_vehicle_id'];

    public function scopeOpen($query)
    {
        return $query->whereNull('uncoupled_at');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function trailer()
    {
        return $this->belongsTo(Trailer::class, 'trailer_id');
    }

    /** How long the pairing lasted, in hours, or null while it is still open. */
    public function getHoursCoupledAttribute(): ?float
    {
        if (! $this->uncoupled_at || ! $this->coupled_at) {
            return null;
        }

        return round($this->coupled_at->diffInMinutes($this->uncoupled_at) / 60, 1);
    }
}
