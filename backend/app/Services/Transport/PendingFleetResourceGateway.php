<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\Contracts\FleetResourceGateway;
use Illuminate\Support\Facades\Log;

/**
 * The gateway that deliberately does nothing.
 *
 * TODO(Person 2 / Fleet): replace this with a real reserve/release service.
 * ---------------------------------------------------------------------------
 * BRW-050 requires a confirmed dispatch to set Vehicle = In Operation and
 * Driver = On Trip. transport_vehicles and transport_drivers belong to Fleet,
 * and the owner ruled on 2026-09-10 that Trip side must not write them.
 *
 * So this records the intent and returns false. Nothing is mutated. Binding a
 * real implementation to FleetResourceGateway is the whole of the change on
 * Trip side's part — no dispatch code needs editing.
 *
 * WHAT FLEET WILL NEED TO HANDLE
 *   - idempotency: dispatch can be confirmed once and amended repeatedly
 *   - a vehicle already `in_operation`, or one that broke down and is now
 *     `maintenance` — the transition must not be forced
 *   - VehicleStatus has no `in_operation` value today; FLEET §7's vocabulary
 *     and VehicleStatus::ALL will need reconciling before this can be honest
 *   - the same treatment for AllocationService, which still writes both tables
 *     directly (SNG-TRN-009, predates the split)
 *
 * Until then a dispatched trip's vehicle keeps reading `allocated` and its
 * driver `assigned`. That is WRONG but VISIBLE — the audit trail records what
 * was intended, so the discrepancy is discoverable rather than silent, and no
 * screen claims a state that was never applied.
 */
class PendingFleetResourceGateway implements FleetResourceGateway
{
    public function markDispatched(
        TransportTrip $trip,
        ?int $vehicleId,
        ?int $driverId,
        int $tenantId,
        ?User $actor = null,
    ): bool {
        Log::channel('transport')->info('Fleet resource update SKIPPED — pending Person 2 gateway', [
            'rule'       => 'BRW-050',
            'intent'     => 'vehicle -> in operation, driver -> on trip',
            'trip_id'    => $trip->id,
            'vehicle_id' => $vehicleId,
            'driver_id'  => $driverId,
            'tenant_id'  => $tenantId,
            'user_id'    => $actor?->id,
            'boundary'   => 'transport_vehicles / transport_drivers are owned by Fleet (Person 2)',
        ]);

        return false;
    }

    /**
     * STT-006 — added 2026-09-19 when the interface grew it.
     *
     * Same contract as above: records the intent, applies nothing, and answers
     * false so Trip side can say on screen that the fleet was not updated
     * rather than implying it was.
     */
    public function markDeparted(
        TransportTrip $trip,
        ?int $vehicleId,
        int $tenantId,
        ?User $actor = null,
    ): bool {
        Log::channel('transport')->info('Fleet resource update SKIPPED — pending Person 2 gateway', [
            'rule'       => 'STT-006',
            'intent'     => 'vehicle -> in transit',
            'trip_id'    => $trip->id,
            'vehicle_id' => $vehicleId,
            'tenant_id'  => $tenantId,
            'user_id'    => $actor?->id,
        ]);

        return false;
    }

    /**
     * Release — added 2026-09-19 when the interface grew it.
     *
     * Worth stating what NOT applying this costs, because it is the one that
     * compounds: every dispatched vehicle stays committed forever and the fleet
     * reports no availability at all.
     */
    public function markReleased(
        ?int $vehicleId,
        ?int $driverId,
        int $tenantId,
    ): bool {
        Log::channel('transport')->info('Fleet resource release SKIPPED — pending Person 2 gateway', [
            'intent'     => 'vehicle -> available, driver -> available',
            'vehicle_id' => $vehicleId,
            'driver_id'  => $driverId,
            'tenant_id'  => $tenantId,
        ]);

        return false;
    }
}
