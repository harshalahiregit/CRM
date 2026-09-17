<?php

namespace App\Domains\Fleet\Integration;

use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\Contracts\FleetResourceGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * C-05 — Fleet's side of the dispatch seam. BRW-050.
 *
 * This is the implementation `PendingFleetResourceGateway` was holding a place
 * for; its TODO read `TODO(Person 2 / Fleet)`. Trip side states its intent
 * through the interface and stops — what happens to a vehicle and a driver when
 * a trip departs is Fleet's to decide and Fleet's to own.
 *
 * ── The four things the contract demands, and how each is met ─────────────
 *
 * **Idempotent.** A dispatch is confirmed once and amended repeatedly. Applying
 * the same departure twice is a no-op, and it still returns true — the caller
 * asked for a state, and the state holds.
 *
 * **Never throws.** Trip side treats a gateway failure as non-fatal, and it is
 * right to: refusing a real dispatch because a status column did not flip would
 * stop a loaded truck leaving a yard over bookkeeping. Everything is wrapped,
 * and a failure is logged and reported as `false`.
 *
 * **Never forces a transition.** A vehicle that broke down between allocation
 * and departure is in the workshop, and dispatch saying "in operation" does not
 * make it so. Those vehicles are left exactly as they are, and the refusal is
 * logged with its reason — silently overwriting would produce a fleet board
 * claiming a truck is on the road when it is on an axle stand.
 *
 * **Honest return value.** `true` only when every resource that could be moved
 * was moved. A partial application returns false, so the discrepancy is
 * discoverable rather than assumed away.
 */
class TransportFleetResourceGateway implements FleetResourceGateway
{
    /**
     * States a vehicle may NOT be dragged out of by a dispatch.
     *
     * Retired is self-evident. In-maintenance means a job card is open and the
     * workshop has not released it — the release gate is the only thing that
     * puts that vehicle back on the road, and it checks compliance and QC first.
     */
    private const IMMOVABLE = ['in_maintenance', 'retired'];

    public function markDispatched(
        TransportTrip $trip,
        ?int $vehicleId,
        ?int $driverId,
        int $tenantId,
        ?User $actor = null,
    ): bool {
        try {
            return DB::transaction(function () use ($trip, $vehicleId, $driverId, $tenantId, $actor) {
                $vehicleApplied = $this->moveVehicle($vehicleId, $tenantId, $trip, $actor);
                $driverApplied = $this->moveDriver($driverId, $tenantId, $trip, $actor);

                // Nothing to move is not a failure — an unassigned trip is a
                // legitimate state, and the caller already knows it passed null.
                return $vehicleApplied && $driverApplied;
            });
        } catch (\Throwable $e) {
            // The contract says never throw. A dispatch must not be lost
            // because Fleet had a bad minute.
            Log::channel('stos')->error('Fleet gateway failed to apply a dispatch', [
                'rule' => 'BRW-050', 'trip_id' => $trip->id,
                'vehicle_id' => $vehicleId, 'driver_id' => $driverId,
                'tenant_id' => $tenantId, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Vehicle → in operation. Returns false only when it could not be moved. */
    private function moveVehicle(?int $vehicleId, int $tenantId, TransportTrip $trip, ?User $actor): bool
    {
        if (! $vehicleId) {
            return true;
        }

        $vehicle = Vehicle::forCompany($tenantId)->find($vehicleId);

        if (! $vehicle) {
            // After the D-62 merge, trips point at `vehicles`. An id that
            // resolves to nothing means the move did not reach this trip —
            // worth saying loudly rather than returning a quiet true.
            Log::channel('stos')->warning('Dispatch named a vehicle that is not in the fleet', [
                'rule' => 'BRW-050', 'trip_id' => $trip->id, 'vehicle_id' => $vehicleId, 'tenant_id' => $tenantId,
            ]);

            return false;
        }

        if ($vehicle->status === Vehicle::STATUS_IN_OPERATION) {
            return true;    // already there — idempotent
        }

        if (in_array($vehicle->status, self::IMMOVABLE, true)) {
            Log::channel('stos')->warning('Dispatch could not move a vehicle out of its current state', [
                'rule' => 'BRW-050', 'trip_id' => $trip->id, 'vehicle_id' => $vehicle->id,
                'status' => $vehicle->status,
                'why' => 'a workshop release, not a dispatch, is what puts this vehicle back on the road',
            ]);

            return false;
        }

        // Goes through the model so the status observer fires and Developers 1
        // and 3 hear `fleet.vehicle.status_changed`.
        $vehicle->update(['status' => Vehicle::STATUS_IN_OPERATION]);

        Log::channel('stos')->info('Vehicle marked in operation', [
            'rule' => 'BRW-050', 'trip_id' => $trip->id, 'vehicle_id' => $vehicle->id,
            'user_id' => $actor?->id,
        ]);

        return true;
    }

    /** Driver → on trip. */
    private function moveDriver(?int $driverId, int $tenantId, TransportTrip $trip, ?User $actor): bool
    {
        if (! $driverId) {
            return true;
        }

        $profile = DriverProfile::forCompany($tenantId)->find($driverId);

        if (! $profile) {
            Log::channel('stos')->warning('Dispatch named a driver with no fleet profile', [
                'rule' => 'BRW-050', 'trip_id' => $trip->id, 'driver_id' => $driverId, 'tenant_id' => $tenantId,
            ]);

            return false;
        }

        if ($profile->status === 'on_trip') {
            return true;
        }

        // A suspended driver is suspended for a reason a dispatch does not
        // overrule. Same principle as the workshop hold on a vehicle.
        if ($profile->status === 'suspended') {
            Log::channel('stos')->warning('Dispatch could not put a suspended driver on a trip', [
                'rule' => 'BRW-050', 'trip_id' => $trip->id, 'driver_profile_id' => $profile->id,
            ]);

            return false;
        }

        $profile->update(['status' => 'on_trip']);

        return true;
    }

    /**
     * The other half of BRW-050, which the interface does not yet name: a trip
     * ends and the resources come back.
     *
     * Not part of C-05, but the state it sets is meaningless without it — a
     * fleet where every vehicle is permanently "in operation" is a fleet with no
     * availability. Offered here so Trip side has something to call at
     * STT-007/012; nothing calls it yet.
     */
    public function markReleased(?int $vehicleId, ?int $driverId, int $tenantId): bool
    {
        try {
            if ($vehicleId) {
                Vehicle::forCompany($tenantId)
                    ->where('id', $vehicleId)
                    ->where('status', Vehicle::STATUS_IN_OPERATION)
                    ->get()
                    ->each(fn (Vehicle $v) => $v->update(['status' => 'active']));
            }

            if ($driverId) {
                DriverProfile::forCompany($tenantId)
                    ->where('id', $driverId)
                    ->where('status', 'on_trip')
                    ->get()
                    ->each(fn (DriverProfile $p) => $p->update(['status' => 'available']));
            }

            return true;
        } catch (\Throwable $e) {
            Log::channel('stos')->error('Fleet gateway failed to release resources', [
                'vehicle_id' => $vehicleId, 'driver_id' => $driverId, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
