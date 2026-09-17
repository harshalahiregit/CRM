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
 *   (no ticket)   viability_pending → approved, and back to draft (STT-002/003)
 *                 No ticket owns it; ruled by the owner 2026-09-16 because the
 *                 whole chain after trip creation was unreachable. D-58/D-59.
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

        // STT-002 | viability_pending → approved | trigger "Approve viable trip"
        //         | actor ApprovalService | precondition "Margin policy passed"
        //         | side effect "Emit TripApproved" | audited | LOCKED
        //
        // SHIPPED WITHOUT ITS PRECONDITION, BY RULING. "Margin policy passed"
        // needs SNG-TRN-008 (Trip Viability), which is not built and is blocked
        // on SNG-TRN-005's rate card — a P0 ticket with NO ASSIGNED OWNER — and
        // on Person 3's unbuilt trip_costs. Approval today checks the state and
        // the permission and nothing about the commercials. See D-59, which
        // carries a test written to fail the day viability lands.
        // STT-003 | viability_pending → draft | trigger "Reject for correction"
        //         | actor Operations | precondition "Rejection reason"
        //         | side effect "Return to edit" | audited | LOCKED
        //
        // Both of viability_pending's exits, wired together: a reviewer who can
        // only say yes is not reviewing. Without this, a trip that should NOT be
        // approved had nowhere to go — the dead end STT-002 only half-fixed.
        self::VIABILITY_PENDING => [self::APPROVED, self::DRAFT],

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
        //
        // STT-007 (in_transit → delivered) follows it and is equally unwired,
        // for the same reason — there is no `in_transit` to leave.

        // STT-008 | delivered → pod_verified | trigger "Verify POD"
        //         | actor DocumentEngine | guard "POD valid"
        //         | side effect "Unlock billing" | audited | LOCKED
        //
        // SNG-TRN-014. Declared here rather than only inside
        // TripDocumentService, so the edge is checkable by the same
        // canTransition() every other edge answers to.
        //
        // UNREACHABLE TODAY, and honestly so: nothing writes `delivered`
        // because STT-006 and STT-007 above are P1's and not yet built. The
        // service therefore moves the trip only when it is actually standing
        // in `delivered`, and records the document either way. Raised as C-09.
        self::DELIVERED => [self::POD_VERIFIED],

        // STT-009 | pod_verified → billable | trigger "Billing validation"
        //         | actor BillingEngine | guard "No blockers"
        //         | side effect "Create billing draft" | audited | LOCKED
        //
        // SNG-TRN-015. The guard is TripDocumentService::billingReadiness() —
        // a verified POD, or an approved exception waiving one — which is
        // 014's acceptance criterion doing the work 015 depends on.
        self::POD_VERIFIED => [self::BILLABLE],

        // STT-010 | billable → billed | trigger "Post invoice"
        //         | actor ACCOUNTS | guard "Approval/posting success"
        //         | side effect "Emit invoice event" | audited | LOCKED
        //
        // The actor is Accounts, not Transport, and this module never posts an
        // invoice (FORBID-002, LOCK-004). The edge is declared here because the
        // state machine is the trip's and the trip is Transport's — but it is
        // walked only from TripBill::markInvoiced(), which is the door Accounts
        // calls after they have posted. Their act, our bookkeeping of it.
        self::BILLABLE => [self::BILLED],

        // STT-011 | billed → collection_pending | trigger "Invoice posted"
        //         | actor Collections | guard "Receivable exists"
        //         | side effect "Create collection task" | audited | LOCKED
        //
        // SNG-TRN-016. TripCollectionService::open() is that side effect, and
        // the guard is a prepared bill — there is nothing to collect until
        // Transport has frozen an amount.
        self::BILLED => [self::COLLECTION_PENDING],

        // ── STT-012 IS NOT WIRED, AND IS NOT P3'S ────────────────────────
        // collection_pending → closed | actor TripEngine | guard
        // "Settlement/POD/billing controls pass" | effect "Snapshot profit".
        // TripEngine is P1's, and the snapshot it triggers is SNG-TRN-018,
        // which is blocked on D-58. Left unwired deliberately.

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
