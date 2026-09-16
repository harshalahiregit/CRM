<?php

namespace App\Support\Transport;

/**
 * Trip lifecycle (SNG-TRN-007) — Step 9's LOCKED state machine.
 *
 * Step 9 is the highest product authority and its 16 states are reproduced here
 * exactly. Four variants exist across the documents (Step 9: 16, Step 11: 12,
 * STOS-OPS §39: 16 differently-named, STOS-DB §36: 11); Step 9 was ruled
 * definitive in the scope agreement, and Step 11's 12 are a strict subset of it
 * with no name conflicts, so both are satisfied by this list.
 *
 * Step 9's control rule, verbatim: "Each transition has preconditions and audit;
 * exceptions do not create hidden states." Hence there is no EXCEPTION state —
 * an exception is a separate record (SNG-TRN-013), never a trip status.
 *
 * ── SCOPE NOTE ────────────────────────────────────────────────────────────
 * All sixteen states are DECLARED so the vocabulary is fixed before anything
 * writes to the column. Far fewer are WIRED: an edge appears in TRANSITIONS only
 * when the ticket that owns its preconditions has built them, because declaring
 * an unguarded edge would let a caller move a trip to `dispatched` with none of
 * the checks that state exists to represent — a hidden state change dressed up
 * as configuration.
 *
 * Wired so far, and by whom:
 *   SNG-TRN-007   draft → viability_pending                    (STT-001)
 *   SNG-TRN-009   approved → allocated, and back on release    (STT-004)
 *   SNG-TRN-010   allocated → pretrip_ok, and back on release  (STT-005, part)
 *   (no ticket)   pretrip_ok → dispatched                      (STT-005 dest.)
 *                 Authorised directly by the owner 2026-09-10; see DispatchScope.
 *
 * Still dead: in_transit onward. STT-006 is SNG-TRN-013's Transit half, blocked
 * on the owner's Q1/Q3 ruling; billable onward belongs to 015-018.
 *
 * Transport-owned. Stored on transport_trips.status as a plain string.
 */
final class TripStatus
{
    public const DRAFT               = 'draft';
    public const VIABILITY_PENDING   = 'viability_pending';
    public const APPROVED            = 'approved';
    public const ALLOCATED           = 'allocated';
    public const PRETRIP_OK          = 'pretrip_ok';
    public const DISPATCHED          = 'dispatched';
    public const IN_TRANSIT          = 'in_transit';
    public const ARRIVED             = 'arrived';
    public const DELIVERED           = 'delivered';
    public const POD_PENDING         = 'pod_pending';
    public const POD_VERIFIED        = 'pod_verified';
    public const BILLABLE            = 'billable';
    public const BILLED              = 'billed';
    public const COLLECTION_PENDING  = 'collection_pending';
    public const SETTLEMENT_PENDING  = 'settlement_pending';
    public const CLOSED              = 'closed';

    /** Step 9 order, which is also the operational order. */
    public const ALL = [
        self::DRAFT, self::VIABILITY_PENDING, self::APPROVED, self::ALLOCATED,
        self::PRETRIP_OK, self::DISPATCHED, self::IN_TRANSIT, self::ARRIVED,
        self::DELIVERED, self::POD_PENDING, self::POD_VERIFIED, self::BILLABLE,
        self::BILLED, self::COLLECTION_PENDING, self::SETTLEMENT_PENDING, self::CLOSED,
    ];

    /** Step 11 SM-TRP: draft is initial, closed is the only terminal state. */
    public const INITIAL  = self::DRAFT;
    public const TERMINAL = [self::CLOSED];

    /**
     * Transitions IMPLEMENTED BY THIS TICKET.
     *
     * Only STT-001. Declaring the whole chain here would let a caller move a
     * trip to `dispatched` with none of the preconditions ticket 010 exists to
     * enforce — a hidden state change dressed as configuration. Each later
     * ticket adds its own edge alongside its guards.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        // STT-001 | draft → viability_pending | trigger "Submit viability"
        //         | precondition "Required fields present" | audited | LOCKED
        self::DRAFT => [self::VIABILITY_PENDING],

        // STT-004 | approved → allocated | trigger "Assign eligible resources"
        //         | actor AssignmentService | precondition "Vehicle/driver valid"
        //         | side effect "Create assignment" | audited | LOCKED
        //
        // SM-TRP's entry gate for `allocated` reads "Vehicle+driver eligible", so
        // this fires only when BOTH are assigned — a vehicle-only assignment
        // leaves the trip in approved. Ruled 2026-09-08.
        self::APPROVED => [self::ALLOCATED],

        // STT-005's precondition work | allocated → pretrip_ok
        //         | trigger "Pass pre-trip" | precondition "All checks passed"
        //         | side effect "Record departure readiness" | audited | LOCKED
        //
        // ── THE Q1 RULING, LIVE ──────────────────────────────────────────
        // Step 11 draws STT-005 as ONE edge, allocated → dispatched. Step 9 —
        // the higher authority, and the machine this enum implements — puts a
        // state between them: ALLOCATED → PRETRIP_OK → DISPATCHED. Ticket 009
        // never had to choose, because approved → allocated is identical in both
        // documents; 010 is the first ticket where they genuinely diverge.
        //
        // Ruled 2026-09-09 by the owner: Step 9 wins, as it has in every prior
        // ruling. STT-005's precondition ("All checks passed") and its side
        // effect ("Record departure readiness") land on THIS edge. Its
        // destination lands on the next one, which nobody owns.
        //
        // Neither edge has an STT row of its own. Recorded as D-17.
        // See PretripScope::STATE_EDGE_OWNED, which carries the ruling as data.
        self::ALLOCATED => [self::APPROVED, self::PRETRIP_OK],

        // STT-005's destination | pretrip_ok → dispatched
        //         | SM-TRP: `dispatched` active, entry gate "Pre-trip passed",
        //           exit gate "In transit", owner Dispatcher | LOCKED
        //
        // ── AUTHORISED WITHOUT A TICKET ──────────────────────────────────
        // Deferred on 2026-09-09 because no Step 12 ticket owns dispatch
        // confirmation (D-18) and the five FRS fields did not exist. The owner
        // authorised the bounded scope in writing on 2026-09-10 and those fields
        // now exist, so the edge is live. See DispatchScope.
        //
        // Under SNG-TRN-010's Q1 ruling, STT-005 is served by Step 9's two
        // edges: its precondition and side effect landed on
        // allocated → pretrip_ok; its destination lands here.
        self::PRETRIP_OK => [self::APPROVED, self::DISPATCHED],

        // ── STILL NOT WIRED ──────────────────────────────────────────────
        // STT-006 (dispatched → in_transit) is the Transit half of
        // SNG-TRN-013, blocked on the owner's Q1/Q3 ruling. Nothing writes
        // `in_transit`. DispatchScope::STATE_EDGE_DEFERRED names it as data.

        // ── INFERRED, NOT A REGISTRY TRANSITION ──────────────────────────
        // pretrip_ok → approved, when an assignment is released.
        //
        // The same argument as the edge below, one state further on: a trip whose
        // crew has been released no longer meets `pretrip_ok`'s entry gate, and
        // leaving it there would assert that a released vehicle and driver had
        // passed their pre-trip checks.
        //
        // It reverts to `approved` rather than to `allocated` because release
        // removes BOTH resources, so `allocated`'s own gate ("Vehicle+driver
        // eligible") is no longer met either — landing there would trade one
        // false assertion for another.
        //
        // Release is permitted from this state rather than refused, and that is
        // a decision the folder supports rather than an omission: OPS §27 says
        // that when a vehicle becomes unavailable Sangoe "shall identify
        // active/future trips; identify replacement vehicle", and BRWM's
        // automatic-action matrix answers "Vehicle unavailable" with
        // "Reallocation". A breakdown does not ask permission, and a system that
        // refused release here would strand the trip with a vehicle it cannot use.

        // ── INFERRED, NOT A REGISTRY TRANSITION ──────────────────────────
        // allocated → approved, when an assignment is released.
        //
        // SM-TRP declares no reverse for this state: STT-004 goes in, STT-005
        // goes out to dispatched, and nothing comes back. But leaving a trip in
        // `allocated` after its crew is released asserts something false — that
        // state's own entry gate ("Vehicle+driver eligible") is no longer met.
        //
        // STT-003 (viability_pending → draft, "Reject for correction") is the
        // registry's own precedent that this machine does move backwards, which
        // is why this is inferred rather than invented outright. It is still NOT
        // quoted from Step 11, and is raised as a registry gap.
        // Ruled 2026-09-08. See docs/transport/registry-defects.md.
    ];

    /** Transitions this module added without a registry row behind them. */
    public const INFERRED_TRANSITIONS = [
        self::ALLOCATED.'->'.self::APPROVED,
        self::PRETRIP_OK.'->'.self::APPROVED,
    ];

    /** Trips a workspace would consider live. Used for list filters only. */
    public const OPEN = [
        self::DRAFT, self::VIABILITY_PENDING, self::APPROVED, self::ALLOCATED,
        self::PRETRIP_OK, self::DISPATCHED, self::IN_TRANSIT, self::ARRIVED,
        self::DELIVERED, self::POD_PENDING, self::POD_VERIFIED, self::BILLABLE,
        self::BILLED, self::COLLECTION_PENDING, self::SETTLEMENT_PENDING,
    ];

    public const LABELS = [
        self::DRAFT              => 'Draft',
        self::VIABILITY_PENDING  => 'Viability pending',
        self::APPROVED           => 'Approved',
        self::ALLOCATED          => 'Allocated',
        self::PRETRIP_OK         => 'Ready to dispatch',
        self::DISPATCHED         => 'Dispatched',
        self::IN_TRANSIT         => 'In transit',
        self::ARRIVED            => 'Arrived',
        self::DELIVERED          => 'Delivered',
        self::POD_PENDING        => 'POD pending',
        self::POD_VERIFIED       => 'POD verified',
        self::BILLABLE           => 'Billable',
        self::BILLED             => 'Billed',
        self::COLLECTION_PENDING => 'Collection pending',
        self::SETTLEMENT_PENDING => 'Settlement pending',
        self::CLOSED             => 'Closed',
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /** True only for transitions this ticket actually implements and guards. */
    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
