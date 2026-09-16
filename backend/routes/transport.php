<?php

use App\Http\Controllers\Api\Transport\TransportAdvanceController;
use App\Http\Controllers\Api\Transport\TransportAllocationController;
use App\Http\Controllers\Api\Transport\TransportCapabilityController;
use App\Http\Controllers\Api\Transport\TransportConsignmentController;
use App\Http\Controllers\Api\Transport\TransportContainerController;
use App\Http\Controllers\Api\Transport\TransportCostController;
use App\Http\Controllers\Api\Transport\TransportDispatchController;
use App\Http\Controllers\Api\Transport\TransportDriverController;
use App\Http\Controllers\Api\Transport\TransportOrderController;
use App\Http\Controllers\Api\Transport\TransportPodController;
use App\Http\Controllers\Api\Transport\TransportPretripController;
use App\Http\Controllers\Api\Transport\TransportResourceCommitmentController;
use App\Http\Controllers\Api\Transport\TransportTripController;
use App\Http\Controllers\Api\Transport\TransportVehicleController;
use App\Support\Transport\TransportPermission;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sangoe Transport OS (STOS)
|--------------------------------------------------------------------------
|
| SNG-TRN-001 — bounded context.  SNG-TRN-006 — orders.  SNG-TRN-007 — trips.
|
| Gated in two layers from the first commit:
|
|   role:admin,staff        the coarse door — matches routes/purchase.php:46 and
|                           keeps portal identities (client, vendor, TPV,
|                           company) off the staff surface entirely.
|   transport.permission:*  the fine gate — the Step 11 matrix (PERM-001/002),
|                           applied PER GROUP rather than per method, for the
|                           reason EnsureCanManageHrQueue records: a check inside
|                           each method is the one somebody forgets to add, and
|                           the method they forget is the index() that lists the
|                           whole tenant.
|
| Read and write are separate groups precisely so a viewer cannot reach a writer
| route by accident — Step 11's rule is deny by default, and that is only true if
| the gate is narrow enough to mean something.
|
| Path prefix is unversioned /api/transport/... matching all 2,658 existing
| routes rather than introducing /api/v1/ for one module (agreed decision Q6).
| Idempotency keys are waived for R1 except on GPS and e-way bill ingest, which
| belong to SNG-TRN-020 / 025 and are not registered here.
|
*/

Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('transport')->group(function () {

    /* ── Orders — read (PERM: order.view) ─────────────────────────────── */
    Route::middleware('transport.permission:'.TransportPermission::ORDER_VIEW)->group(function () {
        Route::get('/orders/status-counts', [TransportOrderController::class, 'statusCounts']);
        Route::get('/orders',              [TransportOrderController::class, 'index']);
        Route::get('/orders/{id}',         [TransportOrderController::class, 'show'])->whereNumber('id');
    });

    /* ── Orders — write (PERM: order.create / order.update) ───────────── */
    Route::middleware('transport.permission:'.TransportPermission::ORDER_CREATE)->group(function () {
        Route::post('/orders', [TransportOrderController::class, 'store']);
    });

    Route::middleware('transport.permission:'.TransportPermission::ORDER_UPDATE)->group(function () {
        Route::put('/orders/{id}',            [TransportOrderController::class, 'update'])->whereNumber('id');
        Route::patch('/orders/{id}/status',   [TransportOrderController::class, 'transition'])->whereNumber('id');
    });

    /* ── Trips — read (PERM-001) ──────────────────────────────────────── */
    Route::middleware('transport.permission:'.TransportPermission::TRIP_VIEW)->group(function () {
        Route::get('/trips/status-counts', [TransportTripController::class, 'statusCounts']);
        Route::get('/trips',              [TransportTripController::class, 'index']);
        Route::get('/trips/{id}',         [TransportTripController::class, 'show'])->whereNumber('id');

        /* Advances, read — SNG-TRN-011. Step 11 has no PERM row for viewing an
         * advance, and inventing one would be FORBID-001. A trip's advances are
         * part of that trip, so they sit behind PERM-001 with the trip itself. */
        Route::get('/trips/{id}/advances', [TransportAdvanceController::class, 'index'])->whereNumber('id');
    });

    /* ── Advances — SNG-TRN-011 ───────────────────────────────────────────
     *
     * Two groups, not one, and the split is the control. PERM-006 lets
     * Operations and Dispatcher ASK; PERM-007 does not let them ALLOW. Putting
     * both verbs behind one permission would collapse the segregation that
     * STOS-FIN §129 and BRW §75 require, and TripAdvanceService adds the
     * narrower rule on top — nobody decides the request they raised.
     */
    Route::middleware('transport.permission:'.TransportPermission::ADVANCE_REQUEST)->group(function () {
        Route::post('/trips/{id}/advances', [TransportAdvanceController::class, 'store'])->whereNumber('id');
    });

    Route::middleware('transport.permission:'.TransportPermission::ADVANCE_APPROVE)->group(function () {
        Route::post('/trips/{id}/advances/{advanceId}/approve', [TransportAdvanceController::class, 'approve'])
            ->whereNumber('id')->whereNumber('advanceId');
        Route::post('/trips/{id}/advances/{advanceId}/reject', [TransportAdvanceController::class, 'reject'])
            ->whereNumber('id')->whereNumber('advanceId');
    });

    /* ── Trip costs — SNG-TRN-012 ─────────────────────────────────────────
     *
     * Three groups, not one, and the widths are the control. Step 11 has no
     * Cost permission row at all (D-58), so these keys are constructed — which
     * is exactly why they are applied narrowly rather than folded into the trip
     * gate. Recording mirrors PERM-008 `Expense/submit`; retracting is narrower
     * than recording, because taking a cost back out changes a reported margin.
     *
     * Reading is separate from TRIP_VIEW on purpose. PERM-001 shows a Customer
     * and a Supplier the trip they are party to; what that haul cost us is not
     * theirs to read, and COST_VIEW omits both.
     */
    Route::middleware('transport.permission:'.TransportPermission::COST_VIEW)->group(function () {
        Route::get('/trips/{id}/costs', [TransportCostController::class, 'index'])->whereNumber('id');
    });

    Route::middleware('transport.permission:'.TransportPermission::COST_RECORD)->group(function () {
        Route::post('/trips/{id}/costs', [TransportCostController::class, 'store'])->whereNumber('id');
    });

    Route::middleware('transport.permission:'.TransportPermission::COST_RETRACT)->group(function () {
        Route::delete('/trips/{id}/costs/{costId}', [TransportCostController::class, 'destroy'])
            ->whereNumber('id')->whereNumber('costId');
    });

    /* ── POD and trip documents — SNG-TRN-014, API-008 ────────────────────
     *
     * Three groups, and the split between submitting and verifying is the
     * control rather than tidiness. PERM-010 lets a Driver submit their own POD
     * and a Supplier submit against trips assigned to them; POD_VERIFY does not
     * let either of them decide it is valid. Whoever hands in the evidence does
     * not certify it — STT-008's effect is "Unlock billing", and that is money.
     *
     * Reading sits behind TRIP_VIEW: a trip's paperwork is part of that trip,
     * and Step 11 has no POD-view row to name a narrower key with. Inventing
     * one would be FORBID-001.
     *
     * API-008's path is `/trips/{trip}/pod`, and that is kept even though DB-009
     * indexes LR and e-way bills too — the registry named this endpoint and
     * `document_type` carries the rest.
     */
    Route::middleware('transport.permission:'.TransportPermission::TRIP_VIEW)->group(function () {
        Route::get('/trips/{id}/documents', [TransportPodController::class, 'index'])->whereNumber('id');
    });

    Route::middleware('transport.permission:'.TransportPermission::POD_SUBMIT)->group(function () {
        Route::post('/trips/{id}/pod', [TransportPodController::class, 'store'])->whereNumber('id');
    });

    Route::middleware('transport.permission:'.TransportPermission::POD_VERIFY)->group(function () {
        Route::post('/trips/{id}/pod/{documentId}/verify', [TransportPodController::class, 'verify'])
            ->whereNumber('id')->whereNumber('documentId');
        Route::post('/trips/{id}/pod/{documentId}/reject', [TransportPodController::class, 'reject'])
            ->whereNumber('id')->whereNumber('documentId');
    });

    /* ── What this user may do ────────────────────────────────────────
     *
     * Ungated on purpose: asking what you may do needs no permission, and a 403
     * here would leave the UI unable to tell "you may not" from "the server is
     * broken". Lets a screen hide an action the API would refuse instead of
     * offering a button that 403s.
     */
    Route::get('/permissions', [TransportCapabilityController::class, 'index']);

    /* ── Vehicle master (SNG-TRN-003) ─────────────────────────────────
     *
     * Gated on transport.vehicle.* — permission rows added for defect D-8,
     * where Step 11's Permissions sheet has no Vehicle or Driver domain at all.
     * Reads are wider than writes; deletion is Owner/Admin only. FLAGGED there.
     */
    Route::middleware('transport.permission:'.TransportPermission::VEHICLE_VIEW)->group(function () {
        Route::get('/vehicles/status-counts', [TransportVehicleController::class, 'statusCounts']);
        Route::get('/vehicles',               [TransportVehicleController::class, 'index']);
        Route::get('/vehicles/{id}',          [TransportVehicleController::class, 'show'])->whereNumber('id');
    });

    Route::middleware('transport.permission:'.TransportPermission::VEHICLE_CREATE)->group(function () {
        Route::post('/vehicles',                     [TransportVehicleController::class, 'store']);
        Route::post('/vehicles/{id}/documents',      [TransportVehicleController::class, 'storeDocument'])->whereNumber('id');
        Route::post('/vehicles/{id}/documents/{documentId}/renew', [TransportVehicleController::class, 'renewDocument'])->whereNumber('id')->whereNumber('documentId');
    });

    Route::middleware('transport.permission:'.TransportPermission::VEHICLE_UPDATE)->group(function () {
        Route::put('/vehicles/{id}',          [TransportVehicleController::class, 'update'])->whereNumber('id');
        // FLEET §8 — status is a business event, not an editable field.
        Route::patch('/vehicles/{id}/status', [TransportVehicleController::class, 'transition'])->whereNumber('id');
    });

    Route::middleware('transport.permission:'.TransportPermission::VEHICLE_DELETE)->group(function () {
        Route::delete('/vehicles/{id}', [TransportVehicleController::class, 'destroy'])->whereNumber('id');
    });

    /* ── Driver master (SNG-TRN-004) ──────────────────────────────────── */
    Route::middleware('transport.permission:'.TransportPermission::DRIVER_VIEW)->group(function () {
        Route::get('/drivers/status-counts', [TransportDriverController::class, 'statusCounts']);
        Route::get('/drivers',               [TransportDriverController::class, 'index']);
        Route::get('/drivers/{id}',          [TransportDriverController::class, 'show'])->whereNumber('id');
    });

    Route::middleware('transport.permission:'.TransportPermission::DRIVER_CREATE)->group(function () {
        Route::post('/drivers',                    [TransportDriverController::class, 'store']);
        Route::post('/drivers/{id}/documents',     [TransportDriverController::class, 'storeDocument'])->whereNumber('id');
        Route::post('/drivers/{id}/documents/{documentId}/renew', [TransportDriverController::class, 'renewDocument'])->whereNumber('id')->whereNumber('documentId');
    });

    Route::middleware('transport.permission:'.TransportPermission::DRIVER_UPDATE)->group(function () {
        Route::put('/drivers/{id}',          [TransportDriverController::class, 'update'])->whereNumber('id');
        // Two axes, one at a time — the request says which.
        Route::patch('/drivers/{id}/status', [TransportDriverController::class, 'transition'])->whereNumber('id');
    });

    Route::middleware('transport.permission:'.TransportPermission::DRIVER_DELETE)->group(function () {
        Route::delete('/drivers/{id}', [TransportDriverController::class, 'destroy'])->whereNumber('id');
    });

    /* ── Allocation (SNG-TRN-009) — PERM-004 ──────────────────────────
     *
     * API-004 is the only one of the three the registry defines. Candidates and
     * release have no registry row (defect D-12); paths ruled 2026-09-08.
     *
     * All three take transport.trip.assign. Candidates deliberately shares it
     * rather than getting a weaker gate — enumerating the fleet against a trip
     * you may not crew serves no purpose, and Step 11 defines no separate
     * permission that could express one (defect D-8).
     */
    Route::middleware('transport.permission:'.TransportPermission::TRIP_ASSIGN)->group(function () {
        Route::post('/trips/{trip}/assign',      [TransportAllocationController::class, 'assign'])->whereNumber('trip');
        Route::get('/trips/{trip}/candidates',   [TransportAllocationController::class, 'candidates'])->whereNumber('trip');
        Route::delete('/trips/{trip}/assign',    [TransportAllocationController::class, 'release'])->whereNumber('trip');
    });

    /* ── Pre-trip checks (SNG-TRN-010) — defect D-21 ──────────────────
     *
     * Step 5 specifies exactly ONE of these paths, POST .../prechecks. Step 11's
     * API registry has no pre-trip endpoint at all (D-15), so the other three
     * follow this module's existing conventions rather than inventing new ones:
     * GET on the collection reads, PATCH changes one field of one record, and a
     * state transition is a PATCH on a named verb — as
     * PATCH /trips/{id}/submit-viability already is for STT-001.
     *
     * Reads and writes are separate groups, so someone who may watch a trip's
     * readiness cannot confirm a safety check.
     *
     * `pass-pretrip` is deliberately NOT named `dispatch`. It reaches
     * `pretrip_ok` and stops; dispatch confirmation belongs to no ticket (D-18).
     */
    Route::middleware('transport.permission:'.TransportPermission::PRETRIP_VIEW)->group(function () {
        Route::get('/trips/{trip}/prechecks', [TransportPretripController::class, 'index'])->whereNumber('trip');
    });

    Route::middleware('transport.permission:'.TransportPermission::PRETRIP_PERFORM)->group(function () {
        Route::post('/trips/{trip}/prechecks',           [TransportPretripController::class, 'store'])->whereNumber('trip');
        Route::patch('/trips/{trip}/prechecks/{check}',  [TransportPretripController::class, 'complete'])->whereNumber('trip')->whereNumber('check');
        Route::patch('/trips/{trip}/pass-pretrip',       [TransportPretripController::class, 'pass'])->whereNumber('trip');
    });

    /* ── Dispatch — RTM STOS-REQ-OPS-008, FRS TRP-P0-006 ──────────────
     *
     * NO TICKET AND NO REGISTRY ROW. Step 12 owns no dispatch ticket (D-18) and
     * Step 11's API registry names no dispatch endpoint. The owner authorised
     * this bounded scope in writing on 2026-09-10; see DispatchScope.
     *
     * PATCH on a named verb, as submit-viability and pass-pretrip already are.
     * `dispatch/amend` is separate from `dispatch` because TRP-P0-006 freezes
     * the fields at release: changing one afterwards is a different act that
     * must carry a reason and create a version.
     *
     * Gated on transport.trip.dispatch, which mirrors PERM-004 — SM-TRP makes
     * the Dispatcher the owner of both `allocated` and `dispatched`.
     */
    // Reading a trip's dispatch state is not releasing it, so the GET sits on
    // transport.trip.view — the same read/write split PRETRIP_VIEW and
    // PRETRIP_PERFORM already draw. It carries the live readiness verdict and
    // the version history, so a panel can explain a block before the user acts
    // (UX §35, BRW-048) and can show TRP-P0-006's history without a second call.
    Route::middleware('transport.permission:'.TransportPermission::TRIP_VIEW)->group(function () {
        Route::get('/trips/{trip}/dispatch', [TransportDispatchController::class, 'show'])->whereNumber('trip');
    });

    Route::middleware('transport.permission:'.TransportPermission::TRIP_DISPATCH)->group(function () {
        Route::patch('/trips/{trip}/dispatch',       [TransportDispatchController::class, 'confirm'])->whereNumber('trip');
        Route::patch('/trips/{trip}/dispatch/amend', [TransportDispatchController::class, 'amend'])->whereNumber('trip');
    });

    /* ── Consignments — STOS-CTD §8 ───────────────────────────────────
     *
     * NO REGISTRY ROW, AND NO TICKET. Step 11's API registry names no
     * consignment endpoint and Step 12's register owns no consignment ticket
     * (D-38). The entity exists by explicit architecture approval (D-39); the
     * permission keys follow the D-8/D-21 precedent for a missing Permissions
     * row (D-45). Paths follow the conventions this module already set.
     *
     * Reads and writes are separate groups, so someone who may watch a customer's
     * shipments cannot alter them. Delete is narrower still — removing a
     * shipment record is not an ordinary edit (mirrors VEHICLE_DELETE).
     *
     * Every one of these sits inside the file's role:admin,staff group. There is
     * deliberately no customer-facing read: SCOPE_OWN narrows nothing today
     * (D-46), so a customer reaching any of these would receive the tenant's
     * whole list. TransportRouteExposureTest fails the build if one escapes.
     */
    Route::middleware('transport.permission:'.TransportPermission::CONSIGNMENT_VIEW)->group(function () {
        Route::get('/consignments',            [TransportConsignmentController::class, 'index']);
        Route::get('/consignments/{id}',       [TransportConsignmentController::class, 'show'])->whereNumber('id');
        // CTD-003 — every consignment on one order.
        Route::get('/orders/{order}/consignments', [TransportConsignmentController::class, 'forOrder'])->whereNumber('order');
    });

    Route::middleware('transport.permission:'.TransportPermission::CONSIGNMENT_CREATE)->group(function () {
        Route::post('/consignments', [TransportConsignmentController::class, 'store']);
    });

    Route::middleware('transport.permission:'.TransportPermission::CONSIGNMENT_UPDATE)->group(function () {
        Route::put('/consignments/{id}', [TransportConsignmentController::class, 'update'])->whereNumber('id');

        /* ── Documents — ORD-005, ORD-006, CTD-004, CTD-005, all P0 ───────
         *
         * Gated on CONSIGNMENT_UPDATE rather than a document permission of
         * their own, for the same reason the vehicle and driver routes are:
         * Step 11's PERM registry has thirteen rows and none of them is
         * "file a document". Inventing a fourteenth would be FORBID-001, and
         * filing paperwork against a shipment IS changing that shipment.
         * Recorded as a gap in docs/transport/TEAM-CONTRACTS.md.
         */
        Route::post('/consignments/{id}/documents', [TransportConsignmentController::class, 'storeDocument'])->whereNumber('id');
        Route::post('/consignments/{id}/documents/{documentId}/renew', [TransportConsignmentController::class, 'renewDocument'])
            ->whereNumber('id')->whereNumber('documentId');
    });

    Route::middleware('transport.permission:'.TransportPermission::CONSIGNMENT_DELETE)->group(function () {
        Route::delete('/consignments/{id}', [TransportConsignmentController::class, 'destroy'])->whereNumber('id');
    });

    /* ── What my trips say about a vehicle or driver ──────────────────
     *
     * UX §35 — "never merely show Blocked, show why". Reads trip_assignments
     * and transport_trips only. It reports a COMMITMENT, never an availability
     * verdict: deciding whether a resource may be used is Person 2's allocation
     * scoring (TM-001 §9) and must not migrate here.
     *
     * TRIP_VIEW, not a key of its own — everything it returns is a fact about a
     * trip, and whoever may read trips may read this.
     */
    Route::middleware('transport.permission:'.TransportPermission::TRIP_VIEW)->group(function () {
        Route::get('/resource-commitments', [TransportResourceCommitmentController::class, 'index']);
    });

    /* ── Containers — STOS-CTD §7, §8 ─────────────────────────────────
     *
     * NO REGISTRY ROW, AND NO TICKET — the same position as Consignment, and
     * recorded the same way (D-45 for the permission keys, D-38 for the absent
     * ticket). Step 11 was searched for `container` and has no row of any kind.
     *
     * THERE IS NO PUT AND NO DELETE, deliberately. The container number is the
     * identity, and STOS-CTD §7 requires historical associations be maintained;
     * editing the number would rewrite that history and deleting the container
     * would destroy it. A container leaves a consignment by DETACH, which keeps
     * the row.
     *
     * attach and detach share one permission. They are one authority — deciding
     * what is on a consignment — and splitting them would let someone attach a
     * container they could not then remove.
     *
     * Every one of these sits inside the file's role:admin,staff group. There is
     * deliberately no customer-facing read, and it matters more here than
     * anywhere else: STOS-CTD's Digital Passport is container-keyed, so this is
     * exactly the surface a customer route would expose while SCOPE_OWN still
     * narrows nothing (D-46). TransportRouteExposureTest fails the build if one
     * escapes.
     */
    Route::middleware('transport.permission:'.TransportPermission::CONTAINER_VIEW)->group(function () {
        Route::get('/containers', [TransportContainerController::class, 'index']);
        // CTD-001 — before /containers/{id}, though whereNumber already keeps
        // them apart. Ordering it defensively costs nothing and survives
        // somebody removing the constraint.
        Route::get('/containers/lookup', [TransportContainerController::class, 'lookup']);
        Route::get('/containers/{id}',   [TransportContainerController::class, 'show'])->whereNumber('id');
        // STOS-CTD §8 — a consignment may carry one container or several.
        Route::get('/consignments/{consignment}/containers', [TransportContainerController::class, 'forConsignment'])
            ->whereNumber('consignment');
    });

    Route::middleware('transport.permission:'.TransportPermission::CONTAINER_CREATE)->group(function () {
        Route::post('/containers', [TransportContainerController::class, 'store']);
    });

    Route::middleware('transport.permission:'.TransportPermission::CONTAINER_ATTACH)->group(function () {
        Route::post('/containers/{id}/attach', [TransportContainerController::class, 'attach'])->whereNumber('id');
        Route::post('/containers/{id}/detach', [TransportContainerController::class, 'detach'])->whereNumber('id');
    });

    /* ── Trips — write (PERM-002) ─────────────────────────────────────── */
    Route::middleware('transport.permission:'.TransportPermission::TRIP_CREATE)->group(function () {
        Route::post('/trips',                   [TransportTripController::class, 'store']);
        Route::put('/trips/{id}',               [TransportTripController::class, 'update'])->whereNumber('id');
        // STT-001 — the only transition this ticket owns.
        Route::patch('/trips/{id}/submit-viability', [TransportTripController::class, 'submitForViability'])->whereNumber('id');
    });
});
