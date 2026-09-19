<?php

namespace App\Services\Transport\Contracts;

use App\Models\Transport\TransportTrip;
use App\Models\User;

/**
 * The one door between Trip side and Fleet's tables.
 *
 * ── WHY THIS INTERFACE EXISTS ─────────────────────────────────────────────
 * BRW-050 says that when dispatch is confirmed the system must "Update Vehicle
 * = In Operation" and "Update Driver = On Trip". Both live in
 * transport_vehicles and transport_drivers, which under the three-developer
 * split belong to Person 2 (Fleet).
 *
 * ── THE NAMING, BECAUSE IT HAS ALREADY BEEN THREE THINGS ─────────────────
 * STOS-TM-001 is the approved baseline and it names the three developers
 * Person 1 (Core/Commercial/Operations), Person 2 (Fleet) and Person 3
 * (Documents/Compliance/Billing). Use those names.
 *
 * Earlier comments in this module said "Developer A" for Fleet, and an earlier
 * planning note used "Dev A" for the Core role — the opposite party. Three
 * vocabularies for three people is how a TODO addressed to someone gets read by
 * the wrong person, so the letters are gone and TM-001's names are the only
 * ones used from 2026-09-12 onward.
 *
 * The owner ruled on 2026-09-10: Trip side must NOT write those tables. So this
 * interface is the seam. Dispatch states its intent through it and stops; what
 * happens on the other side is Fleet's to decide and Fleet's to own.
 *
 * ── THE SAME PROBLEM ALREADY EXISTS ONE TICKET BACK ───────────────────────
 * AllocationService (SNG-TRN-009, already merged) writes both tables directly —
 * moveVehicle() sets status to `allocated`, moveDriver() sets availability to
 * `assigned`. That predates the split and is flagged, not fixed here: rewriting
 * a shipped, tested service is not this scope, and doing it silently would be
 * worse than leaving it visible. When Person 2 supplies the real gateway,
 * allocation should move behind it too.
 *
 * ── WHAT AN IMPLEMENTATION OWES ───────────────────────────────────────────
 * Fleet's implementation must be idempotent — dispatch may legitimately be
 * confirmed once and amended several times — and must not throw when a resource
 * is already in the requested state. Trip side treats a gateway failure as
 * non-fatal: a vehicle whose status did not flip is a Fleet problem, and
 * refusing the dispatch over it would block real operations for a bookkeeping
 * mismatch.
 */
interface FleetResourceGateway
{
    /**
     * BRW-050 — the crew on this trip has departed.
     *
     * @param  TransportTrip  $trip     the dispatched trip
     * @param  int|null       $vehicleId  null when no vehicle is assigned
     * @param  int|null       $driverId   null when no driver is assigned
     * @return bool  true when Fleet actually applied it; false when it was
     *               recorded as intent only. Never throws.
     */
    public function markDispatched(
        TransportTrip $trip,
        ?int $vehicleId,
        ?int $driverId,
        int $tenantId,
        ?User $actor = null,
    ): bool;

    /**
     * STT-006 — the truck actually left.
     *
     * Added 2026-09-19 at Person 1's request: his "Record departure" action
     * exists and had no way through this seam. Called on the SAME event, so one
     * departure record moves both the trip and the vehicle — not a second
     * mechanism that can disagree with the first.
     *
     * Dispatch is not departure. `markDispatched()` above leaves the vehicle
     * ALLOCATED: committed to this trip, unable to take another, still in the
     * yard. That gap is the only window in which a planner can swap a truck at
     * no cost, and collapsing the two states closes it.
     *
     * Same four clauses as the rest of this interface: idempotent, never
     * throws, never forces a transition, honest about whether it applied.
     *
     * @param  int|null  $vehicleId  null when no vehicle is assigned
     * @return bool  true when Fleet applied it. False when it could not — a
     *               vehicle that was never ALLOCATED has not departed, it is on
     *               a different trip from the one being recorded.
     */
    public function markDeparted(
        TransportTrip $trip,
        ?int $vehicleId,
        int $tenantId,
        ?User $actor = null,
    ): bool;

    /**
     * The trip is over — give the vehicle and driver back.
     *
     * Added 2026-09-19. Without it every dispatched vehicle stays committed
     * forever and the fleet reports no availability at all, so this is not
     * optional bookkeeping.
     *
     * Works from either on-trip state: a trip can end before it left
     * (cancelled) or after (completed), and both must release.
     */
    public function markReleased(
        ?int $vehicleId,
        ?int $driverId,
        int $tenantId,
    ): bool;
}
