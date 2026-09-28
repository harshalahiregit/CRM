<?php

namespace App\Support\Transport;

/**
 * Transit and Delivery — the approved scope of STT-006 and STT-007.
 *
 * ── D-105: THIS WAS AUTHORISED A WEEK BEFORE IT WAS BUILT ─────────────────
 * Four documents — TripStatus (twice), DispatchScope (three times),
 * TransportDispatchController (twice) and TEAM-CONTRACTS.md — all stated that
 * STT-006 was "blocked on the owner's Q1/Q3 ruling".
 *
 * The ruling had already been given, on 2026-09-10, and it was an APPROVAL:
 *
 *   "Option (b) — build the Exception engine, and also wire dispatched →
 *    in_transit as a manual 'Record departure' action (departed_at,
 *    departed_by columns only). Nothing beyond that — no in_transit →
 *    delivered, no GPS/telemetry/odometer/temperature, no automatic triggers."
 *
 * Migration 2026_12_16_000013 created both columns AND the index
 * transport_trips_tenant_departed_idx. Nothing ever wrote them. Scaffolding
 * that makes a thing look finished is worse than an absence: an absence gets
 * found, and a comment saying "blocked" gets believed. Recorded as D-105.
 *
 * ── WHY MANUAL IS THE HONEST FORM, NOT A SHORTCUT ─────────────────────────
 * BR-P0-010's trigger is literally a "GPS event" and there is no GPS
 * (SNG-TRN-020, P1). But the requirement does not depend on it:
 *
 *   SM-TRP        `in_transit` entry gate "Departure recorded" — a person can
 *                 record a departure; the gate does not name a sensor.
 *   SM-TRP        `delivered` entry gate "Destination confirmed" — likewise.
 *   FRS TRP-P0-011 rules include, verbatim, "manual update fallback".
 *   RTM OPS-009   "Track trip status" · P0 · "Trip lifecycle controlled".
 *   RTM OPS-010   "Record delivery" · P0 · "Delivery confirmed".
 *
 * So the manual path is the specified path with the automatic half missing,
 * not an invention standing in for it.
 *
 * ── WHAT THE OWNER'S Q3 LIMIT MEANS IN PRACTICE ───────────────────────────
 * "departed_at, departed_by columns only" is taken literally. Departure writes
 * two columns and a status. No odometer, no seal number, no location, no
 * trailer, no fuel reading — every one of which some document mentions
 * somewhere, and none of which Q3 permits.
 *
 * Delivery adds exactly the same two-column shape (delivered_at, delivered_by)
 * because Q3 set the precedent for what a manually recorded event on this
 * table looks like. Shortage/damage remarks, signature, geotag and POD images
 * all belong to FRS TRP-P0-013, which is P3's POD capture, not this edge.
 *
 * @see DispatchScope  the edge immediately before this one.
 * @see ClosureScope   the edge at the far end.
 */
final class TransitScope
{
    /** The owner's authorization for STT-006, in place of a ticket. */
    public const AUTHORIZATION_DEPARTURE = "Owner, 2026-09-10 (Q3) — \"wire dispatched → in_transit as a manual 'Record departure' action (departed_at, departed_by columns only)\"";

    /** The owner's authorization for STT-007. */
    public const AUTHORIZATION_DELIVERY = 'Owner, 2026-09-17 — Block 3 plan approved; STT-007 in scope, arrived skipped under the standing Step 9/Step 11 rule';

    /* ── IN SCOPE ────────────────────────────────────────────────────── */

    /** Track trip status. P0. Acceptance: "Trip lifecycle controlled". */
    public const OPS_009 = 'STOS-REQ-OPS-009';

    /** Record delivery. P0. Acceptance: "Delivery confirmed". */
    public const OPS_010 = 'STOS-REQ-OPS-010';

    public const IN_SCOPE = [self::OPS_009, self::OPS_010];

    public const STT_006 = 'STT-006';
    public const STT_007 = 'STT-007';

    public const EDGE_DEPARTURE = TripStatus::DISPATCHED.'->'.TripStatus::IN_TRANSIT;
    public const EDGE_DELIVERY  = TripStatus::IN_TRANSIT.'->'.TripStatus::DELIVERED;

    /** The only columns Q3 permits for departure. Asserted by TransitScopeTest. */
    public const DEPARTURE_FIELDS = ['departed_at', 'departed_by'];

    /** Delivery's equivalent, following the same precedent. */
    public const DELIVERY_FIELDS = ['delivered_at', 'delivered_by'];

    /* ── D-108: neither edge has an API_Registry row ──────────────────── */

    /**
     * Step 11's API registry runs API-001…API-015 and covers create, viability,
     * assign, advance, expense, exception, POD, close, bill, collection,
     * control-room, trip detail, GPS and e-way-bill.
     *
     * STT-006 and STT-007 are the ONLY trip transitions with no row — even
     * STT-012 gets API-009. The paths below follow the convention this module
     * already set (a state change is a PATCH on a named verb, as
     * submit-viability, pass-pretrip and dispatch already are) and are recorded
     * as DERIVED, not quoted. See D-108.
     */
    public const PATHS_DERIVED = [
        self::STT_006 => 'PATCH /api/v1/transport/trips/{trip}/depart',
        self::STT_007 => 'PATCH /api/v1/transport/trips/{trip}/deliver',
    ];

    /* ── Permissions ─────────────────────────────────────────────────── */

    /**
     * Departure reuses TRIP_DISPATCH. No new key, and that is deliberate.
     *
     * Recording that the vehicle actually rolled is the same dispatcher's same
     * act, minutes later; SM-TRP makes the Dispatcher the owner of `dispatched`
     * and TRIP_DISPATCH already mirrors PERM-004's row. A second grant matrix
     * for "the truck has now left" would be a matrix nobody specified.
     */
    public const PERMISSION_DEPARTURE = TransportPermission::TRIP_DISPATCH;

    /**
     * Delivery gets a DERIVED key — D-8/D-45 precedent — mirroring PERM-004.
     *
     * RTM OPS-010's actor is Operations. FRS TRP-P0-013's is "Driver/Delivery",
     * but that row is POD CAPTURE, which is P3's and is gated by PERM-010 where
     * the Driver already holds `own`. The state change is not the POD, and
     * keeping them apart is what preserves P3's boundary: a driver submits
     * their proof; an operator confirms the trip arrived.
     */
    public const PERMISSION_DELIVERY = TransportPermission::TRIP_DELIVER;

    /* ── Side effects, each dispositioned ────────────────────────────── */

    /**
     * 'built'     — this work does it.
     * 'sentence'  — real, but it resolves to something said rather than stored.
     * 'blocked'   — owned by a ticket that cannot start.
     * 'no_model'  — the table exists, the model does not.
     */
    public const SIDE_EFFECTS = [
        // STT-006 — "Start monitoring"
        'Start GPS monitoring'          => 'blocked',    // SNG-TRN-020, P1
        'Start reefer monitoring'       => 'blocked',    // no reefer/genset model
        'Start exception monitoring'    => 'no_model',   // trip_exceptions has schema, no model
        'Start trip timeline'           => 'built',      // the audit trail already is one

        // STT-007 — "Request POD"
        'Request POD'                   => 'sentence',   // see POD_REQUEST_FORM
    ];

    /**
     * "Request POD" is a sentence, and here is why that is the right answer.
     *
     * Reaching `delivered` IS the request. TripDocumentService::verify()
     * already refuses to advance a trip that is not standing in `delivered`,
     * and billingReadiness() already computes what is outstanding — so the
     * state change alone puts P3's screen into exactly the condition the side
     * effect describes.
     *
     * A pod_requests table invented to satisfy two words would be D-9's mistake
     * (a field with no defined values) with a table attached: no document names
     * such a record, so nothing would define when it closes, who owns it, or
     * what a second one means.
     */
    public const POD_REQUEST_FORM = 'Reaching `delivered` is the request. No record is created; the screen says proof of delivery is now required before billing.';

    /* ── Excluded, with reasons ──────────────────────────────────────── */

    public const EXCLUDED = [
        'GPS / telemetry'    => "FRS TRP-P0-011's geofence, idle, route-deviation and ETA rules all trigger on a GPS event. SNG-TRN-020, P1. Q3 forbids it explicitly.",
        'exception engine'   => 'TRP-P0-012 and BR-P0-010. trip_exceptions has a schema and a vocabulary but no model, and is blocked on D-29/D-30. A transit exception has no trigger without GPS anyway.',
        'transit SLA'        => "Q5 — wall-clock only, and the business calendar BRWM §58 requires has no entity (D-34).",
        'trip cancellation'  => 'RTM OPS-011, P1 not P0, and no state for it exists in any machine.',
        'breakdown'          => 'RTM OPS-012 is P0, but it is an EXCEPTION, and the exception engine is blocked.',
        'arrived'            => 'Step 9 places ARRIVED on the delivery edge. No entry gate in any document, no requirement recording an arrival distinct from a delivery, no data model. Declared and unreachable under the standing Step 9/Step 11 rule. D-36, closed.',
        'POD fields'         => "FRS TRP-P0-013's signature, geotag, images and shortage/damage remarks are P3's POD capture, not this state change.",
        'feedback'           => 'MS-001 §14 step 11 says "delivery, feedback and POD". Feedback is PERSON 3\'s — TM-001 §8 Domain Ownership Matrix, "Feedback / Complaints / CAPA | Person 3 | Quality" — and no entity, field or requirement ID for it exists anywhere in the package. Not ours, and unspecified. Raised to P3.',
    ];

    /* ── The rules that bite ─────────────────────────────────────────── */

    /**
     * A departure may be BACKDATED but never pre-dated, and never before the
     * release it followed.
     *
     * A dispatcher records at 11:00 that the truck left at 09:30 — that is the
     * normal case for a manual fallback, and refusing it would push people to
     * record the wrong time rather than the right one. But a departure before
     * `dispatched_at` asserts the vehicle left before it was released, and a
     * departure in the future asserts something that has not happened.
     *
     * The same shape applies to delivery against departure.
     */
    public const TIME_ORDER = 'dispatched_at <= departed_at <= delivered_at <= now()';
}
