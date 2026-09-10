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
 * split belong to Developer A (Fleet).
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
 * worse than leaving it visible. When Developer A supplies the real gateway,
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
}
