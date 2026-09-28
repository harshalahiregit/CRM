<?php

namespace App\Support\Transport;

/**
 * Record Dispatch — the approved scope of `pretrip_ok → dispatched`.
 *
 * ── THIS WORK HAS NO TICKET, AND THAT IS RECORDED ON PURPOSE ──────────────
 * Step 12's 30-ticket register runs SNG-TRN-010 Pre-trip → 011 Advance → 012
 * Cost. Nothing in it owns dispatch confirmation, which is why SNG-TRN-010
 * deferred STOS-REQ-OPS-008 and left `pretrip_ok → dispatched` declared but
 * unwired. That gap is recorded as D-18.
 *
 * AUTHORIZATION: the owner authorised this bounded scope directly on
 * 2026-09-10, in writing, in place of a ticket — "I'm authorizing this scope
 * even though no formal ticket exists yet; treat this message as the
 * authorization". It is recorded here because a reader six months from now will
 * otherwise find code with no ticket behind it and reasonably assume it was
 * invented.
 *
 * ── WHY THIS IS BETTER FOUNDED THAN IT LOOKS ──────────────────────────────
 * Unlike SNG-TRN-005 (D-24), dispatch is missing a TICKET, not a REQUIREMENT.
 * The requirement is specified in four places:
 *
 *   RTM STOS-REQ-OPS-008  "Record dispatch" · P0 · acceptance
 *                         "Dispatch timestamp/status recorded"
 *   FRS TRP-P0-006        Dispatcher · P0 · trigger "Pre-trip passed" ·
 *                         fields "ETD; ETA/TAT; pickup contact; destination;
 *                         instructions" · "Freeze key dispatch fields after
 *                         release; changes create version"
 *   Step 11 SM-TRP        `dispatched` · active · entry gate "Pre-trip passed" ·
 *                         exit gate "In transit" · owner Dispatcher · LOCKED
 *   Step 11 IDX-004       INDEX (company_id, planned_departure_at) · LOCKED
 *
 * IDX-004 is worth pausing on. SNG-TRN-007 recorded it as a defect — a LOCKED
 * index over a field no field registry defines. `planned_departure_at` IS the
 * ETD that FRS TRP-P0-006 asks for, so building it here closes that gap rather
 * than inventing a column name.
 *
 * ── THE FLEET BOUNDARY (owner's ruling, 2026-09-10) ───────────────────────
 * BRW-050 says a confirmed dispatch must "Update Vehicle = In Operation" and
 * "Update Driver = On Trip". Under the three-developer split those are
 * Person 2's tables, and the owner ruled: do NOT write to them.
 *
 * So this module calls FleetResourceGateway and nothing else. The shipped
 * implementation deliberately does nothing but record the intent. See that
 * interface — the whole boundary decision is written there.
 *
 * @see AllocationScope  SNG-TRN-009's equivalent ruling.
 * @see PretripScope     SNG-TRN-010's equivalent ruling.
 */
final class DispatchScope
{
    /** The owner's authorization, in place of a ticket. */
    public const AUTHORIZATION = 'Owner, 2026-09-10 — bounded scope authorised in writing; no Step 12 ticket exists (D-18)';

    /* ── IN SCOPE ────────────────────────────────────────────────────── */

    /** Record dispatch. P0. Acceptance: "Dispatch timestamp/status recorded". */
    public const OPS_008 = 'STOS-REQ-OPS-008';

    public const IN_SCOPE = [self::OPS_008];

    /** The edge this work wires. Step 9's second dispatch edge. */
    public const STATE_EDGE_OWNED = TripStatus::PRETRIP_OK.'->'.TripStatus::DISPATCHED;

    /**
     * The registry transition whose DESTINATION this reaches.
     *
     * Under SNG-TRN-010's Q1 ruling, STT-005 is served by Step 9's two edges:
     * its precondition and side effect landed on `allocated → pretrip_ok`, and
     * its destination lands here.
     *
     * ── STT-006 IS NO LONGER DEFERRED — D-105 ────────────────────────────
     * This constant said, for a week, that dispatched → in_transit was blocked.
     * It never was: the owner authorised it on 2026-09-10 in the SAME message
     * that authorised this scope, and its columns shipped the same day. The
     * name is kept because it is cited elsewhere; the fact is corrected. See
     * TransitScope, which owns that edge, and D-105 for how the stale comment
     * hid an approved piece of work from four different readers.
     */
    public const STT_005 = 'STT-005';

    /** @deprecated D-105 — wired 2026-09-17. Kept because it is cited; see TransitScope::EDGE_DEPARTURE. */
    public const STATE_EDGE_DEFERRED = TripStatus::DISPATCHED.'->'.TripStatus::IN_TRANSIT;

    /** What actually became of it. Asserted by DispatchScopeTest. */
    public const STATE_EDGE_NOW_WIRED = TransitScope::EDGE_DEPARTURE;

    /* ── FRS TRP-P0-006's five fields, and where each one went ────────── */

    public const FIELD_MAP = [
        'ETD'            => 'planned_departure_at',
        'ETA'            => 'planned_arrival_at',
        'pickup contact' => 'pickup_contact',
        'destination'    => 'dispatch_destination',
        'instructions'   => 'dispatch_instructions',
    ];

    /**
     * TAT is NOT a column, and that is a decision rather than an omission.
     *
     * TRP-P0-006 writes "ETA/TAT" as a single field, conflating an instant with
     * a duration. No document defines TAT's boundaries — whether it is
     * ETD→ETA, ETD→return, or gate-in→gate-out — so a stored column would carry
     * a number nobody could interpret. That is exactly the D-9 mistake
     * (`allocation_type`: a field with no defined values), and it is not
     * repeated. The ETD→ETA interval is derivable; anything else needs a ruling.
     */
    public const TAT_DEFERRED = 'FRS TRP-P0-006 writes "ETA/TAT" as one field and defines TAT nowhere. Derivable from ETD→ETA; not stored.';

    /* ── BRW-050's eight side effects, each dispositioned ─────────────── */

    /**
     * 'built'     — this work does it.
     * 'boundary'  — belongs to another developer's tables; routed through a
     *               gateway that currently does nothing.
     * 'no_ticket' — no owner anywhere in the register.
     * 'blocked'   — owned by a ticket that cannot start.
     */
    public const BRW_050_DISPOSITION = [
        'Create/start Trip'                 => 'built',      // the state change itself
        'Update Vehicle = In Operation'     => 'boundary',   // Person 2
        'Update Driver = On Trip'           => 'boundary',   // Person 2
        'Start GPS monitoring'              => 'blocked',    // SNG-TRN-020, P1
        'Start reefer monitoring'           => 'no_ticket',  // no reefer/genset model exists
        'Start trip timeline'               => 'built',      // the audit trail already is one
        'Activate trip SLA'                 => 'no_ticket',  // needs a business calendar — D-34
        'Activate exception monitoring'     => 'blocked',    // trip_exceptions has schema, no model
    ];

    /* ── Excluded, with reasons ───────────────────────────────────────── */

    public const EXCLUDED = [
        'fleet resource state' => "BRW-050's Vehicle/Driver updates. Owner's ruling 2026-09-10: Trip side must not write transport_vehicles or transport_drivers. Routed through FleetResourceGateway, which records intent and does nothing until Person 2 supplies a reserve/release service.",
        'change approval'      => 'TRP-P0-006 requires "Change approval after release". No approval entity exists in Step 11, and every other approval in this package is P1 and deferred (PLN-007, CMP-007, BRW-049, BR-P0-011). An amendment therefore requires a reason and is versioned and audited, but is not gated on an approver.',
        'dispatch override'    => 'BRW-049 — "Only authorized users may override dispatch block." P1, consistent with every other override.',
        'notifications'        => 'TRP-P0-006 names customer/ops notification over WhatsApp/SMS. That is SNG-TRN-021, P1/Backlog.',
        'dispatch pack'        => 'TRP-P0-006\'s acceptance mentions a shareable dispatch pack. Transport has no document generation and no file upload at all (D-22).',
        'TAT'                  => self::TAT_DEFERRED,
        'in_transit'           => 'STT-006 is wired, but by TransitScope and not by this scope — recording dispatch and recording departure are two acts. Nothing HERE writes `in_transit`; DispatchService::recordDeparture() does. D-105.',
    ];

    /* ── The rules that bite ──────────────────────────────────────────── */

    /**
     * BRW-046 — "Vehicle cannot dispatch until all mandatory dispatch checks pass."
     *
     * ENFORCED BY RE-DERIVATION, on the owner's ruling of 2026-09-10.
     *
     * The first cut of this scope let the state alone carry the gate: a trip at
     * `pretrip_ok` had passed, so it could leave. That reads BRW-046 as a
     * statement about the checklist, when it is a statement about the VEHICLE at
     * the moment it departs. A licence expires overnight; the stored row still
     * says `pass`, because that is what was true when it was written.
     *
     * So DispatchService::assertDispatchable() now calls
     * PretripService::revalidate(), which re-evaluates the same checks against
     * live sources and WRITES NOTHING. A trip whose facts have changed is
     * refused, and the refusal names the check, what it says now and what it
     * said before — BRW-048's exact reason, and UX §35's requirement that a
     * screen never merely show Blocked.
     *
     * This is not a departure from CMP §159. Deterministic means the same facts
     * yield the same answer, not that the answer is cached.
     */
    public const BRW_DISPATCH_READINESS = 'BRW-046';

    /** How BRW-046 is satisfied, as data — asserted by DispatchTest. */
    public const READINESS_REVALIDATED = true;

    /**
     * TRP-P0-006's "Version history", and where it is served from.
     *
     * There is no versions table: the audit log already stores an actor, a
     * timestamp and a before/after pair per change. DispatchService::history()
     * reads it back as a history — version 1 is the release, each later version
     * one amendment with the reason it was given.
     */
    public const VERSION_HISTORY = 'DispatchService::history() — reconstructed from transport_audit_logs; no versions table.';

    /** BRW-048 — "If dispatch fails, Sangoe must display exact reason." */
    public const BRW_BLOCK_REASON = 'BRW-048';

    /** BRW-050 — the dispatch event's side effects. */
    public const BRW_DISPATCH_EVENT = 'BRW-050';
}
