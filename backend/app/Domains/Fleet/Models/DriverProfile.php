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

    public const AVAILABLE = 'AVAILABLE';
    public const ON_TRIP   = 'ON_TRIP';
    public const SUSPENDED = 'SUSPENDED';

    /**
     * T-42 — away, and gone, are different facts.
     *
     * One `inactive` used to do both jobs. Rostering with them merged means
     * either chasing somebody who left or writing off somebody who is back on
     * Monday. ON_LEAVE ends; INACTIVE does not.
     */
    public const ON_LEAVE  = 'ON_LEAVE';
    public const INACTIVE  = 'INACTIVE';

    public const STATUSES = [
        self::AVAILABLE, self::ON_TRIP, self::SUSPENDED, self::ON_LEAVE, self::INACTIVE,
    ];

    /**
     * Statuses a person may be put into by hand.
     *
     * ON_TRIP is not one: it is written by the dispatch gateway when a trip
     * takes the driver and cleared when it releases them. Typing it would
     * claim a trip that does not exist.
     */
    public const MANUALLY_SETTABLE = [
        self::AVAILABLE, self::SUSPENDED, self::ON_LEAVE, self::INACTIVE,
    ];

    /** Indian commercial licence classes. */
    public const CLASSES = ['LMV', 'HMV', 'HTV', 'HAZ', 'OTHER'];

    protected $fillable = [
        'company_id', 'source', 'source_id', 'assigned_vehicle_id',
        'licence_number', 'licence_class', 'licence_expiry',
        'medical_expiry',
        'status', 'note',
        // `licence_normalized` is derived in the boot hook below and is
        // deliberately absent here: a caller setting it by hand could put a
        // value under the unique index that no licence number normalises to,
        // which is the one way to defeat D-145's guard from inside the model.
    ];

    protected $casts = [
        'company_id'     => 'integer',
        'source_id'      => 'integer',
        'assigned_vehicle_id' => 'integer',
        'licence_expiry' => 'date',
        'medical_expiry' => 'date',
    ];

    /**
     * D-145 — the normalised licence is derived, never supplied.
     *
     * Written on every save so the unique index added in 2027_01_16 always
     * sees the same shape, whichever path wrote the row: the service, a
     * seeder, a repair script or tinker. Put in the model rather than the
     * service for exactly that reason — the guard has to hold for the writer
     * who did not read the service.
     *
     * An empty or whitespace-only licence number normalises to NULL, not to
     * '', so blanks do not collide with each other under the index. "Not
     * recorded yet" is a normal state for a driver profile.
     */
    protected static function booted(): void
    {
        static::saving(function (self $profile) {
            $normalised = filled($profile->licence_number)
                ? self::normalizeLicence((string) $profile->licence_number)
                : '';

            $profile->licence_normalized = $normalised === '' ? null : $normalised;
        });
    }

    /**
     * Indian driving licences are written many ways — "RJ14 20110012345",
     * "RJ-14-2011-0012345" and "rj1420110012345" are one licence.
     *
     * Same rule as the legacy register used, so a licence that was a duplicate
     * before the move is still a duplicate after it. Deliberately not a format
     * validator: licence formats vary by issuing state and by decade, and a
     * regex strict enough to be useful would reject real drivers.
     */
    public static function normalizeLicence(string $licence): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($licence))) ?? '';
    }

    /** The vehicle this person regularly drives, if any. */
    public function assignedVehicle()
    {
        return $this->belongsTo(Vehicle::class, 'assigned_vehicle_id');
    }

    /** The handle the rest of STOS passes around. */
    /**
     * The driver's name, resolved from the directory — D-135 / D-144. **P1's
     * addition to P2's model, 2026-09-25, awaiting his review.**
     *
     * Not a column and deliberately **not in `$appends`**: `$profile->name`
     * resolves for a PHP reader, and `toArray()` stays exactly as it was. That
     * is the shape this file already asks for — *"the overlay holds a REFERENCE
     * and licence facts. No name, no phone — those are the directory's, and a
     * copy is what goes stale."*
     *
     * P1's first attempt filled the attribute in from a `retrieved` hook. It
     * never reached a save, but it DID reach `toArray()`, and an array that
     * carries a name with nothing marking it derived becomes a copy the moment
     * it is cached, queued or returned in a response somebody caches. The rule
     * above survived the defence.
     *
     * An accessor also only costs the directory lookup when something actually
     * asks for a name. The hook paid it on every read of every profile,
     * including the many that never wanted one.
     *
     * Never null: a name if the directory has one, `#id` if it does not. A
     * blank is indistinguishable from "no driver assigned", which is precisely
     * how D-135 was reported.
     */
    public function getNameAttribute(): string
    {
        return app(\App\Support\Transport\DriverNaming::class)->nameFor($this);
    }

    public function getRefAttribute(): string
    {
        return $this->source.':'.$this->source_id;
    }
}
