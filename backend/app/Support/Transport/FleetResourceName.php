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
 * A vehicle is its plate. A driver is harder: Fleet stores **no names**, because
 * a driver is a reference into a directory plus a licence (D-135). Until the
 * directory lookup is wired into these messages, the licence is what identifies
 * them — worse to read than a name, and honest, which is why it is written down
 * rather than quietly left blank.
 */
final class FleetResourceName
{
    public static function of(Vehicle|DriverProfile|null $resource): string
    {
        if ($resource instanceof Vehicle) {
            return $resource->registration_number ?: 'vehicle #'.$resource->id;
        }

        if ($resource instanceof DriverProfile) {
            // D-135 — the name lives in the directory, not here.
            return $resource->licence_number ?: 'driver #'.$resource->id;
        }

        return 'the resource';
    }
}
