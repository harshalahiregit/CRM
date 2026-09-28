<?php

namespace App\Support\Transport;

use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;

/**
 * How a Fleet vehicle or driver is named in a message a person reads.
 *
 * `TransportVehicle` and `TransportDriver` carried `displayName()`. Fleet's
 * models do not, and adding one to another developer's model to suit our
 * sentences is not ours to do — so the rule lives here, once, rather than as an
 * inline ternary at each of the six call sites that needed it.
 *
 * A vehicle is its plate. A driver is their name, resolved through the
 * directory by `DriverProfile::name` — Fleet stores no names itself, because a
 * driver is a reference into a directory plus a licence (D-135). That accessor
 * never returns blank; it answers `#id` when the directory has nobody, so a
 * message here can never read as though no driver were assigned.
 */
final class FleetResourceName
{
    public static function of(Vehicle|DriverProfile|null $resource): string
    {
        if ($resource instanceof Vehicle) {
            return $resource->registration_number ?: 'vehicle #'.$resource->id;
        }

        if ($resource instanceof DriverProfile) {
            // D-135 — through the accessor, which resolves the directory. This
            // read the licence directly until 2026-09-25, so a pre-trip check
            // said "RJ14 2019 0011221 is assigned" where it meant a person.
            // The accessor never returns blank, so there is no fallback here.
            return $resource->name;
        }

        return 'the resource';
    }
}
