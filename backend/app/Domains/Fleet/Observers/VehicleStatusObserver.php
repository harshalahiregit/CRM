<?php

namespace App\Domains\Fleet\Observers;

use App\Domains\Fleet\Events\VehicleStatusChanged;
use App\Domains\Fleet\Models\Vehicle;

/**
 * Announce every change of a vehicle's availability.
 *
 * An observer rather than a dispatch at each call site, deliberately: a
 * vehicle's status is changed by the job-card workflow, by retirement, and by
 * the nightly compliance sweep, and more paths will appear. Publishing from
 * each of them means the day somebody adds a fourth, Developers 1 and 3 simply
 * stop hearing about it — silently, with nothing failing.
 *
 * Here, the announcement is a property of the change itself and cannot be
 * forgotten.
 */
class VehicleStatusObserver
{
    public function updated(Vehicle $vehicle): void
    {
        // Only availability matters outside this domain. A corrected chassis
        // number is nobody else's business.
        if (! $vehicle->wasChanged('status') && ! $vehicle->wasChanged('compliance_status')) {
            return;
        }

        VehicleStatusChanged::dispatch(
            $vehicle,
            $vehicle->wasChanged('status') ? $vehicle->getOriginal('status') : $vehicle->status,
            $vehicle->wasChanged('compliance_status')
                ? $vehicle->getOriginal('compliance_status')
                : $vehicle->compliance_status,
        );
    }
}
