<?php

namespace App\Domains\Fleet\Events;

use App\Domains\Fleet\Models\Vehicle;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * STOS-FLEET — `fleet.vehicle.status_changed`.
 *
 * A vehicle moved into or out of service. Developer 1 needs it because a truck
 * that just went into the workshop may be on a plan they are still holding;
 * Developer 3 needs it because downtime and compliance blocks are cost events.
 *
 * Both the operational status AND the compliance verdict are reported here.
 * They are different columns but the same question from outside this domain:
 * "can this vehicle work right now?" — a truck grounded by a lapsed insurance
 * is as unavailable as one on an axle stand.
 *
 * Raised by an observer rather than by each call site, so no code path can
 * change a vehicle's state without announcing it.
 */
class VehicleStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    /** The name Developers 1 and 3 listen for (STOS-TM-001). */
    public const NAME = 'fleet.vehicle.status_changed';

    public function __construct(
        public readonly Vehicle $vehicle,
        public readonly ?string $previousStatus,
        public readonly ?string $previousComplianceStatus,
    ) {
    }

    /** Did the vehicle become unavailable for dispatch on this change? */
    public function isNowBlocked(): bool
    {
        return $this->vehicle->status !== Vehicle::STATUS_AVAILABLE
            || in_array($this->vehicle->compliance_status, ['expired', 'blocked'], true);
    }

    /** The cross-team payload. Plain scalars — no models cross the boundary. */
    public function toPayload(): array
    {
        return [
            'event'               => self::NAME,
            'company_id'          => (int) $this->vehicle->company_id,
            'vehicle_id'          => (int) $this->vehicle->id,
            'registration_number' => (string) $this->vehicle->registration_number,
            'status'              => $this->vehicle->status,
            'previous_status'     => $this->previousStatus,
            'compliance_status'   => $this->vehicle->compliance_status,
            'previous_compliance_status' => $this->previousComplianceStatus,
            'available'           => ! $this->isNowBlocked(),
        ];
    }
}
