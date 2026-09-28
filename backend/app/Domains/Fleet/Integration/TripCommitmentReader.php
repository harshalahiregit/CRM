<?php

namespace App\Domains\Fleet\Integration;

use App\Models\Transport\TripAssignment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * STOS-FLEET — "is this asset on a live trip right now?" D-146.
 *
 * ── THE GAP THIS CLOSES ───────────────────────────────────────────────────
 * Person 1 measured it and it was exact: `grep -rn "trip_assignments"
 * app/Domains/Fleet/` returned **0**. Fleet never read the table that records a
 * vehicle or a driver being held by a trip, so `retire()` could put a truck
 * beyond use while it was loaded and moving. The legacy master DID refuse
 * that, and when its write surface became read-only the refusal went with it
 * — the same shape of loss as D-145, found in the same afternoon by asking
 * *"who enforces this after the move?"* rather than assuming somebody does.
 *
 * ── WHY AN INTEGRATION CLASS AND NOT A QUERY IN THE SERVICE ───────────────
 * Fleet does not own trips, and a service reaching into `trip_assignments`
 * directly would put Ops' schema inside Fleet's business logic — where the
 * next change to that table breaks a retirement screen nobody connected to it.
 * This package already holds that seam: `TripTimelinePublisher` and
 * `TransportFleetResourceGateway` are the two existing places Fleet and Ops
 * touch, and this is the third. One file to update when Ops changes shape.
 *
 * ── THE MEANING OF "COMMITTED" IS OPS', NOT OURS ──────────────────────────
 * It is `TripAssignment::active()` and nothing else. Deliberately NOT a list of
 * trip statuses picked by me: choosing which statuses still hold a vehicle
 * would be inventing an availability rule inside Fleet, and Ops already carries
 * that meaning in one place, releases it explicitly, and enforces it under
 * BR-P0-003. Person 1's `ResourceCommitmentService` reads it exactly this way
 * and says so in its own header.
 *
 * ── AND IT DEGRADES ───────────────────────────────────────────────────────
 * Fleet runs as its own application with no Ops module around it — the driver
 * directory already has a standalone mode for the same reason. With no
 * `trip_assignments` table there are no trips, so nothing is committed, and
 * that is a true answer rather than a crash. A query that fails for any other
 * reason is logged and answered the same way: a retirement is a deliberate act
 * by a person at a screen, and blocking every one of them because a read
 * failed would be a worse failure than the one being guarded against.
 */
class TripCommitmentReader
{
    /** The trip holding this vehicle, or null. */
    public function forVehicle(int $vehicleId, int $companyId): ?array
    {
        return $this->lookUp('vehicle_id', $vehicleId, $companyId);
    }

    /** The trip holding this driver, or null. */
    public function forDriver(int $driverId, int $companyId): ?array
    {
        return $this->lookUp('driver_id', $driverId, $companyId);
    }

    /**
     * @return array{trip_id:int,trip_number:?string,trip_status:?string}|null
     */
    private function lookUp(string $column, int $id, int $companyId): ?array
    {
        // No trip module installed is a real answer: nothing is committed. This
        // is the only case that degrades OPEN — a standalone Fleet must still be
        // able to retire a vehicle.
        if (! Schema::hasTable('trip_assignments')) {
            return null;
        }

        try {
            $assignment = TripAssignment::forTenant($companyId)
                ->active()
                ->where($column, $id)
                ->with(['trip:id,trip_number,status'])
                ->first(['id', 'trip_id', $column]);
        } catch (Throwable $e) {
            // D-204 — the table is there and the read failed. "Could not tell" is
            // not "not committed": swallowing it to null would let a truck on a
            // live trip be retired because the check errored. Signal it so the
            // guard fails CLOSED. Table-absent above is the only open path.
            Log::channel('stos')->warning('Trip commitment could not be read', [
                'company_id' => $companyId, 'column' => $column, 'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new TripCommitmentUnavailable(
                'Could not read trip assignments to check whether this asset is on a trip.',
                previous: $e,
            );
        }

        if (! $assignment) {
            return null;
        }

        return [
            'trip_id'     => (int) $assignment->trip_id,
            // An assignment whose trip row is gone still holds the asset. Say
            // so with the id rather than dropping the refusal: the assignment
            // is the record of the commitment, and the trip being unreadable
            // is a reason for more caution, not less.
            'trip_number' => $assignment->trip?->trip_number,
            'trip_status' => $assignment->trip?->status,
        ];
    }

    /** The phrase a refusal uses, so every caller words it the same way. */
    public function describe(array $commitment): string
    {
        return $commitment['trip_number']
            ? 'trip '.$commitment['trip_number']
            : 'trip #'.$commitment['trip_id'];
    }
}
