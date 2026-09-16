<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-FLEET — what Transport knows about a driver, and nothing else.
 *
 * No name, no phone, no address. Those live in the customer/vendor directory
 * that already holds this person, and copying them here is precisely the
 * duplicate-master-data failure golden rule 3 forbids: the copy goes stale the
 * first time somebody corrects the original.
 *
 * `source` + `source_id` is the reference back to that person.
 */
class DriverProfile extends Model
{
    use BelongsToCompany;

    protected $table = 'driver_profiles';

    /** available | on_trip | suspended | inactive. */
    public const STATUSES = ['available', 'on_trip', 'suspended', 'inactive'];

    /** Indian commercial licence classes. */
    public const CLASSES = ['LMV', 'HMV', 'HTV', 'HAZ', 'OTHER'];

    protected $fillable = [
        'company_id', 'source', 'source_id', 'assigned_vehicle_id',
        'licence_number', 'licence_class', 'licence_expiry',
        'status', 'note',
    ];

    protected $casts = [
        'company_id'     => 'integer',
        'source_id'      => 'integer',
        'assigned_vehicle_id' => 'integer',
        'licence_expiry' => 'date',
    ];

    /** The vehicle this person regularly drives, if any. */
    public function assignedVehicle()
    {
        return $this->belongsTo(Vehicle::class, 'assigned_vehicle_id');
    }

    /** The handle the rest of STOS passes around. */
    public function getRefAttribute(): string
    {
        return $this->source.':'.$this->source_id;
    }
}
