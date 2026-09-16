<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-FLEET — a reefer power unit.
 *
 * An asset in its own right: it has a serial, a service life and a maintenance
 * history that follow the UNIT, not the truck it happens to be bolted to today.
 * `vehicle_id` is therefore nullable — unfitted is a normal state.
 */
class Genset extends Model
{
    use BelongsToCompany;

    protected $table = 'gensets';

    public const STATUSES = ['active', 'in_maintenance', 'idle', 'retired'];

    protected $fillable = [
        'company_id',
        'serial_number',
        'vehicle_id',
        'status',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'vehicle_id' => 'integer',
    ];

    /** Nullable: a genset in the yard belongs to no vehicle. */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
