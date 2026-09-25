<?php

namespace App\Services\Transport;

use App\Events\Transport\TripAssigned;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportDocument;
use App\Domains\Fleet\Models\DriverProfile;
use App\Models\Transport\TransportTrip;
use App\Services\Transport\Contracts\FleetResourceGateway;
use App\Services\Transport\TripEventRecorder;
use App\Domains\Fleet\Models\Vehicle;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Support\Transport\AllocationScope;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\FleetResourceName;
use App\Support\Transport\TripStatus;
use App\Support\Transport\VehicleStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The allocation act — SNG-TRN-009 step 6. STT-004.
 *
 * Registry row, verbatim:
 *   STT-004 | SM-TRP | approved → allocated | trigger "Assign eligible resources"
 *           | actor AssignmentService | precondition "Vehicle/driver valid"
 *           | side effect "Create assignment" | Audit Yes | LOCKED
 *
 * ── WHY THIS IS A SEPARATE CLASS FROM TransportTripService ────────────────
 * STT-001 names TripEngine as its actor; STT-004 names AssignmentService. The
 * registry treats them as different actors, so they are different classes. A trip
 * service that also allocated would make "who may move this state" a matter of
 * which method you happened to call.
 *
 * ── THE ORDER OF OPERATIONS IS THE WHOLE DESIGN ──────────────────────────
 *   1. eligibility   — VehicleEligibilityService / DriverEligibilityService.
 *                      Refuse on ANY failed required check. STT-004's
 *                      precondition is "Vehicle/driver valid", and OPS §194 is
 *                      blunt: "No vehicle is allocated without required
 *                      eligibility. No driver is allocated without required
 *                      eligibility."
 *   2. assignment    — TripAssignmentService, which owns the row, the row lock
 *                      and the double-booking indexes (BR-P0-003).
 *   3. trip state    — approved → allocated, but ONLY once both resources are
 *                      set. SM-TRP's entry gate for `allocated` is
 *                      "Vehicle+driver eligible". Ruled 2026-09-08.
 *   4. resource state— vehicle → allocated, driver → assigned, so the masters
 *                      stop advertising a resource that is out on a trip.
 *   5. audit         — against the assignment AND the trip, carrying the full
 *                      eligibility verdict.
 *   6. event         — EVT-005, once, when the trip actually becomes crewed.
 *
 * Eligibility runs BEFORE the assignment row is touched so a refusal leaves
 * nothing behind: the trip stays approved, no row exists, no resource moves.
 *
 * ── WHY THE VERDICT IS WRITTEN INTO THE AUDIT ────────────────────────────
 * Step 9's product philosophy exists to make favour-based allocation reviewable,
 * and STOS-DB §48 says to record the objective facts rather than label anyone.
 * SEC §103 lists "vehicle assignment history" and "driver allocation history"
 * among its fraud controls. A row saying "assigned" proves nothing; a row saying
 * "assigned, and here are the eight checks that passed at that moment" is what
 * makes a later review possible.
 *
 * ── NOT HERE, DELIBERATELY ───────────────────────────────────────────────
 * No score, no ranking, no weights (PLN-008, P1). No override path (PLN-007,
 * P1) — a blocked allocation is refused, full stop. No location, no cost. All
 * recorded in AllocationScope::EXCLUDED.
 */
class AllocationService
{
    public function __construct(
        private TripAssignmentService $assignments,
        private VehicleEligibilityService $vehicleEligibility,
        private DriverEligibilityService $driverEligibility,
        private TransportPolicyService $policies,
        private PretripService $pretrip,
        // The seam to Fleet. Dispatch has always told Fleet when a resource was
        // TAKEN; nothing ever told it when one came free — `markReleased()` sat
        // on the interface with no caller in the codebase. D-119.
        private FleetResourceGateway $fleet,
    ) {
    }

    /**
     * Assign a vehicle and/or driver to an approved trip.
     *
     * Sequential or combined, inherited from step 4: one call may set either or
     * both, and a later call folds into the same assignment row.
     *
     * @param  array{reason?:string,allocation_type?:string,approved_by?:int}  $meta
     * @return array{trip:TransportTrip,assignment:TripAssignment,eligibility:array}
     */
    public function assign(
        TransportTrip $trip,
        ?int $vehicleId,
        ?int $driverId,
        int $tenantId,
        ?User $actor = null,
        array $meta = [],
    ): array {
        $this->assertTenant($trip, $tenantId);

        if ($vehicleId === null && $driverId === null) {
            throw new BusinessException('An allocation needs a vehicle, a driver, or both.', 422);
        }

        // ── Double-submit, handled without an Idempotency-Key header ─────
        //
        // API-004 marks idempotency Required; the standing Q6 ruling waives the
        // header for R1 because the integrity it buys is already guaranteed by a
        // row lock and three unique indexes. That covers CORRECTNESS but not
        // COURTESY: once the first call allocates the trip, a second identical
        // one would hit the from-state guard below and answer 422, which is a
        // confusing reply to a dispatcher who simply double-clicked.
        //
        // So an EXACTLY matching re-request returns the existing state and
        // writes nothing — no audit row, no event, no state change. Anything
        // that differs still falls through and is refused on its merits.
        $active = $this->assignments->activeForTrip($trip->id, $tenantId);
        if ($active !== null
            && ($vehicleId === null || (int) $active->vehicle_id === $vehicleId)
            && ($driverId === null || (int) $active->driver_id === $driverId)
            && ($vehicleId !== null || $driverId !== null)) {
            return [
                'trip'        => $trip->fresh(),
                'assignment'  => $active,
                'eligibility' => [],
                'repeated'    => true,
            ];
        }

        // STT-004's from-state. A trip that has not been approved has no business
        // being crewed, and one already dispatched must not be re-crewed here.
        if ($trip->status !== TripStatus::APPROVED) {
            throw new BusinessException(
                'Only an approved trip can be allocated. This trip is '.$trip->statusLabel().'.',
                422
            );
        }

        $policy   = $this->policies->all($tenantId);
        $verdicts = [];

        /* ── 1. Eligibility. Refuse before anything is written. ─────────── */
        $vehicle = $vehicleId === null ? null : $this->findVehicle($vehicleId, $tenantId);
        $driver  = $driverId === null ? null : $this->findDriver($driverId, $tenantId);

        if ($vehicle) {
            $verdicts['vehicle'] = $this->vehicleEligibility->evaluate($vehicle, $trip, $tenantId, $policy);
            $this->assertEligible($verdicts['vehicle'], 'vehicle', $vehicle, $trip, $tenantId, $actor);
        }
        if ($driver) {
            $verdicts['driver'] = $this->driverEligibility->evaluate($driver, $trip, $tenantId, $policy);
            $this->assertEligible($verdicts['driver'], 'driver', $driver, $trip, $tenantId, $actor);
        }

        $result = DB::transaction(function () use ($trip, $vehicle, $driver, $vehicleId, $driverId, $tenantId, $actor, $meta, $verdicts) {
            /* ── 2. The assignment row. Owns the lock and BR-P0-003. ───── */
            $assignment = $this->assignments->assign($trip, $vehicleId, $driverId, $tenantId, $actor, $meta);

            /* ── 4. Resource states, so the masters stop advertising them. ─ */
            // Fleet's vocabulary. Two traps here, both SILENT if missed —
            // the allocation still writes its row, the resource simply never
            // stops being advertised as free:
            //   · Fleet's statuses are UPPERCASE, so VehicleStatus::AVAILABLE
            //     ('available') never matches.
            //   · `driver_profiles` has NO `availability` column at all, so
            //     $driver->availability is null and lower-casing saves nothing.
            //     A profile's one state is `status`.
            if ($vehicle && in_array($vehicle->status, Vehicle::ALLOCATABLE, true)) {
                $this->moveVehicle($vehicle, Vehicle::STATUS_ALLOCATED, $actor, 'Allocated to trip '.$trip->trip_number);
            }
            if ($driver && $driver->status === DriverProfile::AVAILABLE) {
                $this->moveDriver($driver, DriverProfile::ON_TRIP, $actor, 'Assigned to trip '.$trip->trip_number);
            }

            /* ── 5. Audit, carrying the evidence. ───────────────────────── */
            $evidence = $this->evidence($verdicts);

            $assignment->audit('transport.allocation.performed', $actor, new: [
                'trip_id'    => $trip->id,
                'vehicle_id' => $assignment->vehicle_id,
                'driver_id'  => $assignment->driver_id,
                'complete'   => $assignment->isComplete(),
            ], context: $evidence);

            /* ── 3. Trip state — only when genuinely crewed. ────────────── */
            $becameAllocated = false;
            if ($assignment->isComplete() && $trip->status === TripStatus::APPROVED) {
                $from = $trip->status;
                $trip->forceFill(['status' => TripStatus::ALLOCATED, 'updated_by' => $actor?->id])->save();

                $trip->auditTransition(
                    'transport.trip.status_changed',
                    $from,
                    TripStatus::ALLOCATED,
                    $actor,
                    array_merge($evidence, [
                        'transition'    => 'STT-004',
                        'assignment_id' => $assignment->id,
                    ]),
                );
                $becameAllocated = true;
            } else {
                // A partial allocation is a real, recorded act — it is just not
                // a state change. Saying so on the trip keeps its history
                // readable without having to open the assignment.
                $trip->audit('transport.trip.partially_allocated', $actor, new: [
                    'assignment_id' => $assignment->id,
                    'vehicle_id'    => $assignment->vehicle_id,
                    'driver_id'     => $assignment->driver_id,
                    'awaiting'      => $assignment->hasVehicle() ? 'driver' : 'vehicle',
                ], context: $evidence);
            }

            /* ── 6. EVT-005, once, when the trip becomes crewed. ────────── */
            if ($becameAllocated) {
                TripAssigned::dispatch($trip->fresh(), $assignment->fresh(), $evidence);
            }

            Log::channel('transport')->info('Allocation performed', [
                'trip_id' => $trip->id, 'assignment_id' => $assignment->id,
                'vehicle_id' => $assignment->vehicle_id, 'driver_id' => $assignment->driver_id,
                'became_allocated' => $becameAllocated,
                'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return [
                'trip'        => $trip->fresh(),
                'assignment'  => $assignment->fresh(),
                'eligibility' => $verdicts,
            ];
        });

        /* ── 7. CTD §31's timeline, after the commit. ──────────────────── */
        //
        // Two types, not one: CTD §31 lists "09:15 Driver Allocated" and
        // "09:20 Vehicle Allocated" as separate moments, and they genuinely are
        // — a trip can get its vehicle on Monday and its driver on Tuesday, and
        // one merged line would date the pair to whichever came last.
        //
        // Recorded on what THIS call attached, not on what the assignment now
        // holds: a second call adding the driver must not re-announce the
        // vehicle that was already there. D-115.
        // Written out rather than looped over a [$type => $id] table: the type
        // is the one thing in a recorder call that must stay greppable. The
        // registry is audited by searching for it, and a type assembled from a
        // variable is a type that audit cannot see — which is how these two came
        // to be missing without anyone noticing.
        if ($vehicleId !== null) {
            app(TripEventRecorder::class)->record(
                'vehicle.allocated', trip: $result['trip'], actor: $actor,
                detail: ['vehicle_id' => $vehicleId, 'assignment_id' => $result['assignment']->id],
            );
        }

        if ($driverId !== null) {
            app(TripEventRecorder::class)->record(
                'driver.allocated', trip: $result['trip'], actor: $actor,
                detail: ['driver_id' => $driverId, 'assignment_id' => $result['assignment']->id],
            );
        }

        return $result;
    }

    /**
     * The trip is delivered — give the vehicle and the driver back.
     *
     * ── WHY DELIVERY AND NOT CLOSURE ────────────────────────────────────
     * The owner asked for this and their instinct was right, but the reasoning
     * is worth writing down because it is not obvious and the documents had to
     * be read for it. STOS-OPS §39 and §8 put the operational chain as
     *
     *   DELIVERY → CUSTOMER HANDOVER → FEEDBACK → POD → DOCUMENT RETURN
     *   → BILLING READINESS → ACCOUNTING → OPERATIONAL CLOSURE
     *
     * and §83 states plainly that "operational closure does not necessarily
     * mean accounting closure". Our `closed` is the accounting end: it needs a
     * verified POD, an invoice and a collected payment. Holding a truck until a
     * customer pays would tie a physical asset to a commercial event, which is
     * the thing that separation exists to prevent. STOS-FLEET §16 is the other
     * half of it — "Available — Asset is free" — and after delivery the asset
     * IS free.
     *
     * ── THE DRIVER LOOKED LIKE AN EXCEPTION, AND IS NOT ─────────────────
     * A driver does have a duty after delivery: OPS §78 requires the physical
     * documents to be returned, and creates a "Submit Trip Documents" task. So
     * the obvious question is whether the driver stays held until they do.
     *
     * §79 answers it. The consequence of a late document return is
     * "reminder; supervisor escalation; billing block; management visibility"
     * — a BILLING block, not an availability block. The document says what to
     * withhold and it is money, not the driver. So both come free together.
     *
     * (When §78/§79 are built, that billing block belongs in the billing gate,
     * not here. This method should not acquire a document check.)
     *
     * ── WHAT IT DOES NOT DO ─────────────────────────────────────────────
     * It does not revert the trip and it does not invalidate the pre-trip
     * checklist. `release()` below does both, because that is an ABANDONED
     * allocation — the trip goes back to `approved` and has to be crewed again.
     * This is a COMPLETED one: the trip keeps going to POD, billing and
     * closure, and the checklist it passed is a historical fact about a journey
     * that actually happened.
     *
     * The assignment is moved to RELEASED, which is the vocabulary's only
     * terminal state. There is no COMPLETED — inventing one would be a new
     * state with no Step 11 entry.
     */
    public function releaseOnDelivery(TransportTrip $trip, int $tenantId, ?User $actor = null): ?TripAssignment
    {
        $this->assertTenant($trip, $tenantId);

        $assignment = $this->assignments->activeForTrip($trip->id, $tenantId);

        if (! $assignment) {
            // Nothing held this trip. A trip delivered without an allocation is
            // odd but not an error, and refusing here would block a delivery
            // over a bookkeeping detail.
            return null;
        }

        $vehicleId = $assignment->vehicle_id;
        $driverId  = $assignment->driver_id;

        $released = DB::transaction(function () use ($assignment, $trip, $tenantId, $actor, $vehicleId, $driverId) {
            // clearTripPointers: FALSE. The trip keeps its vehicle_id and
            // driver_id, because on a finished trip those are the record of what
            // ran it, not a claim on a resource. Clearing them broke Container
            // 360, the CTD §4 plate search and the repoint's own row count.
            $row = $this->assignments->release(
                $assignment, $tenantId, $actor, 'Trip '.$trip->trip_number.' delivered',
                clearTripPointers: false,
            );

            $this->freeResources($vehicleId, $driverId, $tenantId, $actor, 'Trip '.$trip->trip_number.' delivered');

            return $row;
        });

        // CTD §31. The timeline should say the crew came free, because a driver
        // quietly becoming available is as confusing as one that never does.
        app(TripEventRecorder::class)->record(
            'crew.released', trip: $trip, actor: $actor,
            detail: array_filter([
                'assignment_id' => $released->id,
                'vehicle_id'    => $vehicleId,
                'driver_id'     => $driverId,
                'because'       => 'delivered',
            ]),
            summary: 'Vehicle and driver released — trip delivered',
        );

        return $released;
    }

    /**
     * Give a vehicle and a driver back, on our side AND on Fleet's.
     *
     * Both guards are deliberate. A vehicle that broke down while allocated must
     * not be quietly marked Available by a trip finishing, so each move is
     * conditioned on the state it is actually in.
     *
     * The Fleet call is the half that was missing everywhere: `markReleased()`
     * existed on the gateway and NOTHING in the codebase called it, so Fleet was
     * told when a resource was taken and never when it came back. D-119.
     */
    private function freeResources(?int $vehicleId, ?int $driverId, int $tenantId, ?User $actor, string $reason): void
    {
        if ($vehicleId) {
            $vehicle = Vehicle::forCompany($tenantId)->find($vehicleId);

            // ON_TRIP_STATES, not just ALLOCATED: a trip that reaches delivery
            // has moved its vehicle on to IN_TRANSIT, and matching only the
            // earlier state would leave every completed trip's truck showing as
            // in transit forever. That is D-119 in its other direction.
            if ($vehicle && in_array($vehicle->status, Vehicle::ON_TRIP_STATES, true)) {
                $this->moveVehicle($vehicle, Vehicle::STATUS_AVAILABLE, $actor, $reason);
            }
        }

        if ($driverId) {
            $driver = DriverProfile::forCompany($tenantId)->find($driverId);
            if ($driver && $driver->status === DriverProfile::ON_TRIP) {
                $this->moveDriver($driver, DriverProfile::AVAILABLE, $actor, $reason);
            }
        }

        // Through the seam, never throwing — same contract as markDispatched.
        $this->fleet->markReleased($vehicleId, $driverId, $tenantId);
    }

    /**
     * Release an allocation and free everything it held.
     *
     * The trip reverts to approved, from `allocated` or from `pretrip_ok`. Both
     * transitions are INFERRED, not registry rows — see
     * TripStatus::INFERRED_TRANSITIONS for why leaving the trip in either state
     * after its crew has gone would state something false.
     *
     * The trip's pre-trip checklist is invalidated at the same time (SNG-TRN-010):
     * it certified a crew that no longer holds the trip, so every confirmation on
     * it has to be earned again.
     */
    public function release(TripAssignment $assignment, int $tenantId, ?User $actor = null, ?string $reason = null): TripAssignment
    {
        if ((int) $assignment->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Assignment');
        }

        // Read before the transaction: the released row may no longer point at
        // the trip, and the event has to say which trip lost its crew.
        $releasedTripId = $assignment->trip_id;

        $freed = DB::transaction(function () use ($assignment, $tenantId, $actor, $reason) {
            $vehicleId = $assignment->vehicle_id;
            $driverId  = $assignment->driver_id;
            $tripId    = $assignment->trip_id;

            $released = $this->assignments->release($assignment, $tenantId, $actor, $reason);

            // Free the resources. Guarded on their current state so a vehicle
            // that broke down while allocated is not quietly marked Available.
            // Same helper as releaseOnDelivery, so BOTH release paths tell
            // Fleet. Before D-119 this branch freed our own two tables and left
            // Fleet holding the resource for ever — the gap was in the abandoned
            // path as well as the completed one.
            $this->freeResources($vehicleId, $driverId, $tenantId, $actor, $reason ?? 'Assignment released');

            $trip = TransportTrip::forTenant($tenantId)->find($tripId);

            // SNG-TRN-010. Both states revert to `approved`, because release
            // removes BOTH resources and neither `allocated` ("Vehicle+driver
            // eligible") nor `pretrip_ok` ("All checks passed") still holds.
            //
            // pretrip_ok is included rather than refused. OPS §27: when a vehicle
            // becomes unavailable Sangoe "shall identify active/future trips;
            // identify replacement vehicle" — and BRWM's automatic-action matrix
            // answers "Vehicle unavailable" with "Reallocation". Refusing release
            // here would strand a trip with a vehicle it cannot use.
            $revertsOnRelease = [TripStatus::ALLOCATED, TripStatus::PRETRIP_OK];

            if ($trip && in_array($trip->status, $revertsOnRelease, true)) {
                $from = $trip->status;
                $trip->forceFill(['status' => TripStatus::APPROVED, 'updated_by' => $actor?->id])->save();
                $trip->auditTransition(
                    'transport.trip.status_changed',
                    $from,
                    TripStatus::APPROVED,
                    $actor,
                    array_filter([
                        'reason'     => $reason,
                        'transition' => 'inferred (no registry row) — assignment released',
                    ]),
                );
            }

            // The checklist certified THIS crew. Once the crew is gone, every
            // confirmation on it vouches for a configuration that no longer
            // exists, so the run is invalidated and regeneration is required
            // before the trip can pass pre-trip again. See
            // PretripService::invalidate() for why the rows are reset rather
            // than deleted.
            if ($trip) {
                $this->pretrip->invalidate(
                    $trip,
                    $tenantId,
                    $actor,
                    'The vehicle and driver were released'.($reason ? ': '.$reason : '.'),
                );
            }

            Log::channel('transport')->info('Allocation released', [
                'assignment_id' => $released->id, 'trip_id' => $tripId,
                'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $released;
        });

        // CTD §31, after the commit. The reason travels with it: a release is
        // one of the few timeline entries a reader will stop at and ask "why",
        // and the answer is already in hand here. D-115.
        app(TripEventRecorder::class)->record(
            'crew.released', tenantId: $tenantId, tripId: $releasedTripId, actor: $actor,
            detail: array_filter([
                'assignment_id' => $freed->id,
                'reason'        => $reason,
            ]),
            summary: $reason ? 'Released: '.$reason : null,
        );

        return $freed;
    }

    /** The candidates a dispatcher may choose from — PLN-002/003. */
    public function candidates(TransportTrip $trip, int $tenantId, bool $includeIneligible = false): array
    {
        $this->assertTenant($trip, $tenantId);

        return [
            'vehicles' => $this->vehicleEligibility->candidatesFor($trip, $tenantId, $includeIneligible)->all(),
            'drivers'  => $this->driverEligibility->candidatesFor($trip, $tenantId, $includeIneligible)->all(),
        ];
    }

    /* ── Internals ──────────────────────────────────────────────────── */

    /**
     * OPS §194 — "No vehicle is allocated without required eligibility."
     *
     * The message carries every failed check, because QA-003 requires a block to
     * be "actionable" and BRWM §70 shows the expected tone: "Upload valid licence
     * or assign another eligible driver."
     *
     * ── THE REFUSAL IS AUDITED BEFORE IT IS THROWN ───────────────────────
     * Two hard rules name an audit artefact in their Audit Evidence column, and
     * blocking correctly only satisfies half of each:
     *
     *   BR-P0-003 (Critical)  "Allocation conflict log"
     *   BR-P0-004 (Critical)  "Document status + override"
     *
     * An audit found neither existed — a refused allocation left no trace at
     * all, so someone reviewing a delayed trip could see it was unallocated but
     * not that three attempts had been refused, nor why.
     *
     * PLACEMENT IS LOad-BEARING. This runs before DB::transaction() opens in
     * assign(), so the row SURVIVES the exception. Written inside the
     * transaction it would roll back with the refusal it exists to record —
     * the precise opposite of the requirement.
     *
     * The subject is the TRIP, because on a refusal there is no assignment row
     * to attach to, and the question this answers ("why is this trip still not
     * crewed?") is asked of the trip.
     *
     * Ruled 2026-09-09: EVERY eligibility refusal is logged, not only the two
     * the rules mandate. The verdict is already computed so it costs nothing,
     * and a trail that records a document refusal but silently drops a capacity
     * refusal is worse than either — a reviewer would see gaps without knowing
     * they were gaps. Precondition and validation failures (wrong trip state, no
     * resource supplied) stay unlogged: they are not allocation conflicts, and
     * logging them would turn the trail into a click log.
     */
    private function assertEligible(
        array $verdict,
        string $kind,
        Vehicle|DriverProfile $resource,
        TransportTrip $trip,
        int $tenantId,
        ?User $actor,
    ): void {
        if ($verdict['eligible']) {
            return;
        }

        $failed = array_values(array_filter($verdict['checks'], fn ($c) => $c['required'] && ! $c['passed']));
        $keys   = array_column($failed, 'key');

        $trip->audit('transport.allocation.refused', $actor, context: [
            // The rule whose Audit Evidence column this row satisfies, or null
            // where the refusal is governed by something that is not a BR-P0
            // rule. `sources` keeps that traceable either way.
            'rule'    => $this->ruleFor($kind, $keys),
            'sources' => $this->sourcesFor($kind, $keys),

            'kind'          => $kind,
            'resource_id'   => (int) $resource->id,
            // Fleet's models carry no displayName(), and adding one to another
            // developer's model to suit our audit row is not ours to do. A
            // vehicle is its plate; a driver is their licence, because Fleet
            // stores no names — a driver is a reference into the CRM directory.
            'resource_name' => $resource instanceof Vehicle
                ? $resource->registration_number
                : ($resource->licence_number ?? 'driver #'.$resource->id),
            'trip_number'   => $trip->trip_number,

            'blockers' => $verdict['blockers'],
            'checks'   => array_map(fn ($c) => [
                'key' => $c['key'], 'required' => $c['required'],
                'passed' => $c['passed'], 'detail' => $c['detail'],
            ], $verdict['checks']),

            // BR-P0-004's "Document status" half, as structured data rather than
            // a sentence — which document, valid until when, still valid or not.
            'document_status' => $this->documentStatus($kind, $resource, $tenantId, $keys, $verdict),

            // BR-P0-004's "+ override" half. PLN-007 is P1 and AllocationScope
            // defers it, so no override can exist yet. The key is present and
            // null so the shape is already right when that ticket lands, and so
            // this row never reads as "no override was used" when the real
            // answer is "overrides do not exist".
            'override' => null,
        ]);

        Log::channel('transport')->info('Allocation refused', [
            'trip_id' => $trip->id, 'kind' => $kind, 'resource_id' => $resource->id,
            'failed' => $keys, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        throw new BusinessException(
            'That '.$kind.' cannot be allocated. '.FleetResourceName::of($resource).': '.implode(' ', $verdict['blockers']),
            422
        );
    }

    /** The BR-P0 rule this refusal is evidence for, where one exists. */
    private function ruleFor(string $kind, array $failedKeys): ?string
    {
        if ($kind === 'vehicle' && in_array('assignment', $failedKeys, true)) {
            return AllocationScope::BR_VEHICLE_OVERLAP;   // BR-P0-003
        }

        if ($kind === 'driver' && array_intersect(['availability', 'lifecycle', 'licence', 'documents'], $failedKeys)) {
            return AllocationScope::BR_DRIVER_BLOCKED;    // BR-P0-004
        }

        return null;
    }

    /**
     * What governs each refusal, including the ones with no BR-P0 rule behind
     * them — so a null `rule` never means "unaccounted for".
     *
     * @return string[]
     */
    private function sourcesFor(string $kind, array $failedKeys): array
    {
        $map = $kind === 'vehicle' ? [
            'status'     => 'STOS-FLEET §7/§8; BRW-044',
            'assignment' => 'BR-P0-003; STOS-DB §198; RTM PLN-006',
            'documents'  => 'QA-003; STOS-FLEET §14; STOS-CMP §21; RTM PLN-005',
            'capacity'   => 'RTM PLN-001; FRS TRP-P0-003 ("payload")',
        ] : [
            'lifecycle'    => 'Step 2 BO-009; RTM PLN-004',
            'availability' => 'BR-P0-004; BRW-028; STOS-DB §44',
            'assignment'   => 'STOS-DB §199; RTM PLN-006',
            'licence'      => 'BR-P0-004; STOS-CMP §22; BRM BR-048',
            'documents'    => 'BR-P0-004; BRW-029; STOS-CMP §24',
        ];

        return array_values(array_intersect_key($map, array_flip($failedKeys)));
    }

    /**
     * BR-P0-004's "Document status" — the papers as they stood at the moment of
     * refusal, so the evidence does not depend on them still looking that way.
     *
     * Only gathered when a document or licence check actually failed; a capacity
     * refusal has no document story to tell.
     */
    private function documentStatus(string $kind, Vehicle|DriverProfile $resource, int $tenantId, array $failedKeys, array $verdict = []): ?array
    {
        // `fleet` joins the list: after the repoint, a driver's licence and
        // medical are Fleet's checks and they arrive under that key, so keying
        // only on the old two would silently record no evidence at all for
        // every driver refusal.
        if (! array_intersect(['documents', 'licence', 'fleet'], $failedKeys)) {
            return null;
        }

        $status = [];

        if ($kind === 'driver') {
            // The evidence for a driver refusal now IS Fleet's verdict — the
            // licence state, the medical state and the profile status, each
            // already naming the desk that can clear it. Copied as given rather
            // than re-derived: a second opinion recorded as evidence is not
            // evidence.
            $status['fleet'] = collect($verdict['checks'] ?? [])
                ->firstWhere('key', 'fleet')['detail'] ?? 'Refused by Fleet.';

            $status['licence'] = [
                'number'      => $resource->licence_number,
                'class'       => $resource->licence_class,
                'valid_until' => $resource->licence_expiry?->toDateString(),
            ];

            return $status;
        }

        $status['documents'] = TransportDocument::forTenant($tenantId)
            ->forVehicle($resource->id)
            ->where('status', TransportDocument::STATUS_ACTIVE)
            ->get()
            ->map(fn (TransportDocument $d) => [
                'type'        => $d->document_type,
                'number'      => $d->document_number,
                'valid_until' => $d->valid_until?->toDateString(),
                'valid'       => $d->isCurrentlyValid(),
            ])->values()->all();

        return $status;
    }

    /**
     * The verdict, reduced to what belongs in an audit row.
     *
     * Every check with its outcome — not just the failures — because a review
     * needs to know what was VERIFIED, not only what went wrong.
     *
     * @param array<string,array<string,mixed>> $verdicts
     */
    private function evidence(array $verdicts): array
    {
        $out = ['rule' => AllocationScope::BR_VEHICLE_OVERLAP, 'checks' => []];

        foreach ($verdicts as $kind => $verdict) {
            $out['checks'][$kind] = array_map(fn ($c) => [
                'key' => $c['key'], 'required' => $c['required'], 'passed' => $c['passed'], 'detail' => $c['detail'],
            ], $verdict['checks']);

            if (! empty($verdict['warnings'])) {
                $out['warnings'][$kind] = $verdict['warnings'];
            }
            if (isset($verdict['compliance_status'])) {
                $out['compliance_status'][$kind] = $verdict['compliance_status'];
            }
        }

        return $out;
    }

    /**
     * Fleet owns the status; we say why it moved.
     *
     * Through `update()` rather than `forceFill()->save()`, because Fleet hangs
     * a status observer off the model and Developers 1 and 3 are listening for
     * `fleet.vehicle.status_changed`. Writing round the model would move the
     * truck and tell nobody.
     */
    private function moveVehicle(Vehicle $vehicle, string $to, ?User $actor, string $reason): void
    {
        $from = $vehicle->status;
        $vehicle->update(['status' => $to]);

        // The status itself is audited by Fleet: the model observer raises
        // `fleet.vehicle.status_changed`, which is now the record of record for
        // a Fleet-owned column. What Fleet cannot know is WHY allocation moved
        // it, so that is what this line adds. Writing a second transitions row
        // from here would give one status change two audit trails that could
        // disagree.
        Log::channel('stos')->info('Vehicle status moved by allocation', [
            'vehicle_id' => $vehicle->id, 'from' => $from, 'to' => $to,
            'reason' => $reason, 'user_id' => $actor?->id,
        ]);
    }

    private function moveDriver(DriverProfile $driver, string $to, ?User $actor, string $reason): void
    {
        $from = $driver->status;
        $driver->update(['status' => $to]);

        Log::channel('stos')->info('Driver status moved by allocation', [
            'driver_profile_id' => $driver->id, 'from' => $from, 'to' => $to,
            'reason' => $reason, 'user_id' => $actor?->id,
        ]);
    }

    private function findVehicle(int $id, int $tenantId): Vehicle
    {
        $vehicle = Vehicle::forCompany($tenantId)->find($id);
        if (! $vehicle) {
            throw new ResourceNotFoundException('Vehicle');
        }

        return $vehicle;
    }

    private function findDriver(int $id, int $tenantId): DriverProfile
    {
        $driver = DriverProfile::forCompany($tenantId)->find($id);
        if (! $driver) {
            throw new ResourceNotFoundException('Driver');
        }

        return $driver;
    }

    private function assertTenant(TransportTrip $trip, int $tenantId): void
    {
        if ((int) $trip->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Trip');
        }
    }
}
