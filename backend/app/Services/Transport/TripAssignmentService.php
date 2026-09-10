<?php

namespace App\Services\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Support\Transport\AllocationScope;
use App\Support\Transport\AssignmentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The assignment record itself — SNG-TRN-009 step 4.
 *
 * ── WHAT THIS CLASS IS AND IS NOT ─────────────────────────────────────────
 * It owns the trip_assignments row and the double-booking guard. It does NOT
 * decide whether a vehicle or driver is eligible — that is
 * Vehicle/DriverEligibilityService in step 5, and AllocationService in step 6
 * composes the two and moves the trip through STT-004. Split this way because
 * "is this resource allowed" and "is this resource free" fail for different
 * reasons and are enforced in different places: eligibility in PHP against
 * documents and policy, freedom in the database against a unique index.
 *
 * Written now rather than with step 6 because of gap G-1: shipping a model whose
 * audit trait nothing calls is how ticket 003 and 004 ended up failing their own
 * acceptance criteria. Every path that creates or changes an assignment goes
 * through here, so the audit is correct by construction before the eligibility
 * layer arrives.
 *
 * ── BR-P0-003, ENFORCED TWICE ON PURPOSE ──────────────────────────────────
 * "Vehicle cannot have overlapping active trips." Hard. Critical.
 *
 *   1. lockForUpdate() inside a transaction, so two concurrent dispatchers
 *      serialise rather than both reading "free" and both writing.
 *      STOS-DB §192 names this exact sequence — "Create Trip + Assign Vehicle +
 *      Assign Driver + Create Events" — as needing a transaction.
 *   2. Unique indexes over generated columns, which hold even for a caller that
 *      never took the lock.
 *
 * The lock produces a readable error; the index produces a constraint violation.
 * Belt and braces, because the failure mode is a truck double-booked at a port
 * gate, discovered by a driver rather than by the system.
 *
 * §193 is respected too: no external call happens inside the transaction.
 */
class TripAssignmentService
{
    /**
     * Assign a vehicle and/or driver to a trip.
     *
     * SEQUENTIAL OR COMBINED, by design. CTR-007/008 mark both fields required on
     * API-004, while FRS TRP-P0-004's trigger is "Vehicle allocated" — the driver
     * is chosen after the vehicle. Both readings are satisfied by letting one
     * call set either or both, and by folding a later call into the SAME row
     * rather than opening a second one. §47 describes one history record per
     * allocation carrying both resources, so a trip has at most one active
     * assignment — enforced by trip_assign_active_trip_uniq.
     *
     * This method does NOT check eligibility and does NOT move the trip's state.
     * Step 6 wraps it.
     *
     * @param  array{reason?:string,allocation_type?:string,approved_by?:int}  $meta
     */
    public function assign(
        TransportTrip $trip,
        ?int $vehicleId,
        ?int $driverId,
        int $tenantId,
        ?User $actor = null,
        array $meta = [],
    ): TripAssignment {
        $this->assertTripTenant($trip, $tenantId);

        if ($vehicleId === null && $driverId === null) {
            throw new BusinessException('An assignment needs a vehicle, a driver, or both.', 422);
        }

        return DB::transaction(function () use ($trip, $vehicleId, $driverId, $tenantId, $actor, $meta) {
            // Serialise concurrent dispatchers. Taken BEFORE reading the current
            // assignment so the read cannot be stale by the time we write.
            $this->lockResources($tenantId, $vehicleId, $driverId);

            $existing = TripAssignment::forTenant($tenantId)
                ->forTrip($trip->id)
                ->active()
                ->lockForUpdate()
                ->first();

            $assignment = $existing
                ? $this->extend($existing, $vehicleId, $driverId, $tenantId, $actor, $meta)
                : $this->open($trip, $vehicleId, $driverId, $tenantId, $actor, $meta);

            // The trip's own vehicle_id/driver_id are a denormalised pointer at
            // the CURRENT assignment (OPS §37: "a trip links customer, vehicle,
            // driver and route"). Kept in step with the assignment here rather
            // than in a second service, because two writers of the same fact is
            // how the two copies drift apart. Not mass-assignable on the model,
            // so forceFill is the deliberate route.
            $trip->forceFill([
                'vehicle_id' => $assignment->vehicle_id,
                'driver_id'  => $assignment->driver_id,
                'updated_by' => $actor?->id,
            ])->save();

            return $assignment->fresh();
        });
    }

    /** A brand-new allocation for a trip that has none. */
    private function open(
        TransportTrip $trip,
        ?int $vehicleId,
        ?int $driverId,
        int $tenantId,
        ?User $actor,
        array $meta,
    ): TripAssignment {
        $this->assertResourcesFree($tenantId, $vehicleId, $driverId);

        /** @var TripAssignment $assignment */
        $assignment = TripAssignment::create([
            'tenant_id'       => $tenantId,
            'trip_id'         => $trip->id,
            'vehicle_id'      => $vehicleId,
            'driver_id'       => $driverId,
            'assigned_at'     => now(),
            'reason'          => $meta['reason'] ?? null,
            'allocation_type' => $meta['allocation_type'] ?? null,
            'approved_by'     => $meta['approved_by'] ?? null,
            'created_by'      => $actor?->id,
            'updated_by'      => $actor?->id,
        ]);

        // EVT-005 TripAssigned carries exactly trip_id, vehicle_id, driver_id.
        // The event itself is emitted by AllocationService in step 6; the audit
        // row records the same facts now so nothing is lost in between.
        $assignment->audit('transport.assignment.created', $actor, new: [
            'trip_id'      => $trip->id,
            'trip_number'  => $trip->trip_number,
            'vehicle_id'   => $assignment->vehicle_id,
            'driver_id'    => $assignment->driver_id,
            'status'       => $assignment->status,
            'rule'         => AllocationScope::BR_VEHICLE_OVERLAP,
        ]);

        $trip->audit('transport.trip.assignment_created', $actor, new: [
            'assignment_id' => $assignment->id,
            'vehicle_id'    => $assignment->vehicle_id,
            'driver_id'     => $assignment->driver_id,
        ]);

        Log::channel('transport')->info('Trip assignment created', [
            'assignment_id' => $assignment->id, 'trip_id' => $trip->id,
            'vehicle_id' => $assignment->vehicle_id, 'driver_id' => $assignment->driver_id,
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $assignment;
    }

    /**
     * Fold a second call into the trip's existing active assignment.
     *
     * This is the "vehicle first, driver later" path. Replacing a resource that
     * is already set is refused: swapping a vehicle mid-assignment is a TRANSFER
     * (OPS §122-123), which must release the old assignment and open a new one so
     * the history survives. That workflow is not in this ticket, so rather than
     * half-implement it, this fails with a message that says so.
     */
    private function extend(
        TripAssignment $assignment,
        ?int $vehicleId,
        ?int $driverId,
        int $tenantId,
        ?User $actor,
        array $meta,
    ): TripAssignment {
        $changes = [];

        foreach (['vehicle_id' => $vehicleId, 'driver_id' => $driverId] as $field => $incoming) {
            if ($incoming === null) {
                continue;
            }
            $current = $assignment->{$field};

            if ($current !== null && (int) $current !== (int) $incoming) {
                throw new BusinessException(
                    'This trip already has a '.str_replace('_id', '', $field).' assigned. '
                    .'Release the assignment before allocating a different one.',
                    422
                );
            }
            if ($current === null) {
                $changes[$field] = (int) $incoming;
            }
        }

        if ($changes === []) {
            // Idempotent: re-sending the same allocation is not an error and must
            // not write a second audit row.
            return $assignment;
        }

        $this->assertResourcesFree($tenantId, $changes['vehicle_id'] ?? null, $changes['driver_id'] ?? null);

        $before = $assignment->only(['vehicle_id', 'driver_id']);
        $assignment->forceFill(array_merge($changes, [
            'updated_by' => $actor?->id,
            'reason'     => $meta['reason'] ?? $assignment->reason,
        ]))->save();

        $assignment->audit(
            'transport.assignment.updated',
            $actor,
            old: $before,
            new: $assignment->only(['vehicle_id', 'driver_id']),
            context: ['sequential' => true],
        );

        Log::channel('transport')->info('Trip assignment extended', [
            'assignment_id' => $assignment->id, 'added' => array_keys($changes),
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $assignment;
    }

    /**
     * Release an assignment — LSM §44's terminal state.
     *
     * The row is never deleted; it becomes history. Releasing frees the vehicle
     * and driver because the generated columns that carry the unique indexes go
     * NULL the moment status leaves the active set.
     */
    public function release(TripAssignment $assignment, int $tenantId, ?User $actor = null, ?string $reason = null): TripAssignment
    {
        $this->assertTenant($assignment, $tenantId);

        if (! $assignment->canTransitionTo(AssignmentStatus::RELEASED)) {
            throw new BusinessException(
                'An assignment cannot be released from '.$assignment->statusLabel().'.',
                422
            );
        }

        return DB::transaction(function () use ($assignment, $tenantId, $actor, $reason) {
            $from = $assignment->status;

            $assignment->forceFill([
                'status'      => AssignmentStatus::RELEASED,
                'released_at' => now(),
                'updated_by'  => $actor?->id,
            ])->save();

            $assignment->auditTransition(
                'transport.assignment.released',
                $from,
                AssignmentStatus::RELEASED,
                $actor,
                array_filter(['reason' => $reason]),
            );

            // The trip's denormalised pointers are cleared with it — a released
            // assignment must not leave the trip claiming a vehicle it no longer
            // holds.
            $trip = TransportTrip::forTenant($tenantId)->find($assignment->trip_id);
            $trip?->forceFill(['vehicle_id' => null, 'driver_id' => null, 'updated_by' => $actor?->id])->save();

            Log::channel('transport')->info('Trip assignment released', [
                'assignment_id' => $assignment->id, 'trip_id' => $assignment->trip_id,
                'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $assignment->fresh();
        });
    }

    /** The active assignment for a trip, if any. */
    public function activeForTrip(int $tripId, int $tenantId): ?TripAssignment
    {
        return TripAssignment::forTenant($tenantId)->forTrip($tripId)->active()->first();
    }

    /** Full allocation history for a trip — SEC §103's fraud control. */
    public function historyForTrip(int $tripId, int $tenantId)
    {
        return TripAssignment::forTenant($tenantId)->forTrip($tripId)->orderByDesc('id')->get();
    }

    /* ── Guards ─────────────────────────────────────────────────────── */

    /**
     * Take row locks on the candidate resources before anyone reads their state.
     *
     * Ordered by table then id so two concurrent transactions acquire locks in
     * the same sequence and cannot deadlock each other.
     */
    private function lockResources(int $tenantId, ?int $vehicleId, ?int $driverId): void
    {
        if ($vehicleId !== null) {
            DB::table('transport_vehicles')->where('tenant_id', $tenantId)
                ->where('id', $vehicleId)->lockForUpdate()->first();
        }
        if ($driverId !== null) {
            DB::table('transport_drivers')->where('tenant_id', $tenantId)
                ->where('id', $driverId)->lockForUpdate()->first();
        }
    }

    /**
     * BR-P0-003 / STOS-DB §198-199 — the readable half of the guard.
     *
     * The unique indexes would catch this anyway, but a constraint violation is
     * not an actionable message. QA-003 requires allocation to be "blocked with
     * an actionable message", and that applies here as much as to documents.
     */
    private function assertResourcesFree(int $tenantId, ?int $vehicleId, ?int $driverId): void
    {
        if ($vehicleId !== null) {
            $clash = TripAssignment::forTenant($tenantId)->forVehicle($vehicleId)->active()->first();
            if ($clash) {
                throw new BusinessException(
                    'That vehicle is already assigned to trip #'.$clash->trip_id.'. Release that assignment first.',
                    422
                );
            }
        }

        if ($driverId !== null) {
            $clash = TripAssignment::forTenant($tenantId)->forDriver($driverId)->active()->first();
            if ($clash) {
                throw new BusinessException(
                    'That driver is already assigned to trip #'.$clash->trip_id.'. Release that assignment first.',
                    422
                );
            }
        }
    }

    private function assertTenant(TripAssignment $assignment, int $tenantId): void
    {
        if ((int) $assignment->tenant_id !== $tenantId) {
            Log::channel('transport')->warning('Assignment tenant mismatch', [
                'assignment_id' => $assignment->id, 'tenant_id' => $tenantId,
            ]);
            throw new ResourceNotFoundException('Assignment');
        }
    }

    private function assertTripTenant(TransportTrip $trip, int $tenantId): void
    {
        if ((int) $trip->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Trip');
        }
    }
}
