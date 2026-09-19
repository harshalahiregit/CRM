<?php

namespace App\Support\Transport;

/**
 * Trip closure — STT-012's approved scope.
 *
 * Unlike every other edge in this module, closure is fully specified:
 *
 *   STT-012   collection_pending → closed | trigger "Close trip" | actor
 *             TripEngine | precondition "Settlement/POD/billing controls pass"
 *             | side effect "Snapshot profit" | audited | LOCKED
 *   SM-TRP    `closed` · TERMINAL · entry gate "Settlement and closure rules
 *             pass" · owner Operations
 *   API-009   POST /api/v1/transport/trips/{trip}/close · permission
 *             `transport.trip.close` · emits TripClosed · LOCKED
 *   CTR-013   closure_reason · body · TEXT · REQUIRED · non-empty ·
 *             "Closure controls run first"
 *   PERM-005  Trip/close — Owner Y, Operations Y, Dispatcher N, Accounts Y,
 *             Approver Y, Driver N, Customer N, Supplier N, Admin Y
 *   EVT-012   TripClosed · payload trip_id, closure_timestamp · idempotency
 *             trip_id+close_version · consumers ProfitEngine, ControlRoom
 *   BR-P0-017 "Trip close requires all critical controls passed or explicit
 *             waiver" · Hard · override Owner role
 *   TRP-P0-014 "no silent closure with unresolved critical exceptions" ·
 *             acceptance "User sees exactly why a trip is blocked"
 *
 * Nothing below is derived. That makes what IS missing sharper.
 *
 * ── IT WAS PLUMBED AND UNREACHABLE FOR TWO DAYS — D-106, CLOSED ───────────
 * `collection_pending` is the only state STT-012 leaves from, and for two days
 * NO USER COULD REACH IT. STT-008 and STT-009 were built and routed, STT-011 was
 * built, but STT-010 (billable → billed) ran through TripBill::markInvoiced(),
 * which had NO CALLER AND NO ROUTE.
 *
 * `trip_bills` is P3's table and the standing rule is not to fix another
 * developer's file to make our own work reachable. So this edge was built to the
 * registry, tested including its refusals, and marked PLUMBED — never BUILT.
 *
 * Person 3 shipped `POST /trips/{id}/bill/invoiced` on 2026-09-19 and it went
 * live WITH NO CHANGE HERE, which was the point of building it this way. A trip
 * has been walked `delivered → closed` in the browser since.
 *
 * TripCollectionService::open() still guards with canTransition() rather than
 * forcing the status, so opening a collection on a `billable` trip creates the
 * row and declines to move the state. That guard was not a workaround for the
 * gap and does not come out now that the gap is closed — it is what keeps the
 * collection row and the trip status from disagreeing.
 *
 * ── BR-P0-017'S WAIVER IS DEFERRED, AND THAT IS A DIFFERENT KIND OF NO ────
 * Every other override this module has refused was UNSPECIFIED — PLN-007,
 * CMP-007, BRW-049 and Q2's `waived` all name an override that no document
 * defines, so building one would have meant inventing the rule.
 *
 * BR-P0-017 is not that. The spec names the waiver AND names the role that may
 * exercise it ("Owner"). This is a SPECIFIED behaviour we are choosing not to
 * build yet, not an invented one we are refusing.
 *
 * Two consequences, both load-bearing:
 *
 *   1. It is logged as DEFERRED against BR-P0-017 with an owner, not as
 *      "no source". See WAIVER_DEFERRED.
 *   2. THE REFUSAL MESSAGE MUST SAY THE WAIVER IS NOT BUILT YET — never that
 *      no waiver exists. A user told "this cannot be waived" when the rule says
 *      it can is being misled by our screen. See WAIVER_MESSAGE.
 *
 * @see TransitScope  the two edges before this one.
 */
final class ClosureScope
{
    public const AUTHORIZATION = 'Owner, 2026-09-17 — Block 3 plan approved, answer (b): build it, test it, and state plainly that it fails the reachability check';

    public const STT_012   = 'STT-012';
    public const API_009   = 'API-009';
    public const CTR_013   = 'CTR-013';
    public const PERM_005  = 'PERM-005';
    public const EVT_012   = 'EVT-012';
    public const BR_017    = 'BR-P0-017';
    public const FRS_014   = 'TRP-P0-014';

    public const EDGE = TripStatus::COLLECTION_PENDING.'->'.TripStatus::CLOSED;

    /** API-009's path and permission key, both quoted. */
    public const PATH       = 'POST /api/v1/transport/trips/{trip}/close';
    public const PERMISSION = TransportPermission::TRIP_CLOSE;

    /** The three columns this edge writes. CTR-013 makes the reason mandatory. */
    public const FIELDS = ['closed_at', 'closed_by', 'closure_reason'];

    /* ── D-106 ───────────────────────────────────────────────────────── */

    /**
     * Stated as data so a test can assert we never quietly claim otherwise.
     *
     * FLIPPED 2026-09-19 by P3. `POST /trips/{id}/bill/invoiced` now calls
     * TripBill::markInvoiced() through TripBillingService, so STT-010 has a
     * caller and a route. The full chain is reachable end to end:
     *
     *   delivered → pod_verified → billable → billed → collection_pending → closed
     *
     * D-106 is closed. Closure moves from PLUMBED to BUILT, and the test that
     * scanned app/ and routes/ for a caller now asserts one EXISTS — it goes red
     * if the route is ever removed, which is the same guard pointing the other
     * way.
     */
    public const REACHABLE = true;

    public const UNREACHABLE_BECAUSE = null;

    /* ── The closure controls ────────────────────────────────────────── */

    /**
     * 'checked'     — we can evaluate it, and a failure blocks closure.
     * 'not_built'   — we CANNOT evaluate it. Reported as not checked. NEVER
     *                 reported as a pass, and never silently skipped.
     *
     * TRP-P0-014's rule is "no SILENT closure with unresolved critical
     * exceptions", and its acceptance is "User sees exactly why a trip is
     * blocked". Saying a check did not run is the opposite of silent; letting
     * an unrunnable check default to true is exactly what the rule forbids.
     */
    public const CONTROLS = [
        'pod'        => 'checked',      // P3's billingReadiness()
        'billing'    => 'checked',      // a trip_bills row carrying an invoice
        'collection' => 'checked',      // CollectionStatus::SETTLED
        'settlement' => 'not_built',    // trip_settlements does not exist — SNG-TRN-017
        // WAS 'not_built'. The exception register was built on 2026-09-18 once
        // D-29 and D-30 were ruled, and this line kept saying otherwise for a
        // day — so TRP-P0-014's "no silent closure with unresolved critical
        // exceptions" was not being enforced while the screen claimed the check
        // could not run. An honest "not checked" becomes a lie the moment the
        // thing it was waiting for exists, and nothing announces that.
        'exceptions' => 'checked',
    ];

    /** Why each unrunnable control cannot run, in the words the screen uses. */
    public const NOT_BUILT_REASONS = [
        'settlement' => 'Supplier and driver settlement is not built yet (SNG-TRN-017 — there is no trip_settlements table), so this control could not be checked.',
    ];

    /**
     * TRP-P0-014's rule, enforced rather than reported, since 2026-09-19.
     *
     * "No silent closure with unresolved critical exceptions." CRITICAL is the
     * word the rule uses, so a critical exception still open BLOCKS the close.
     * An open exception of any other severity is reported and does not block —
     * widening a Hard rule beyond its own wording would be inventing one, and
     * a low-severity note left open is not what "silent closure" means.
     */
    public const EXCEPTION_RULE = 'TRP-P0-014 — no silent closure with unresolved critical exceptions.';

    /* ── BR-P0-017's waiver ──────────────────────────────────────────── */

    /**
     * DEFERRED, with a reference and an owner — not "no source".
     *
     * BR-P0-017 | Close | "Trip close requires all critical controls passed or
     * explicit waiver" | Hard | Override: Owner role | Action: Block/waiver.
     */
    public const WAIVER_DEFERRED = 'BR-P0-017 names a waiver and names the Owner role that may exercise it. Specified, not built. Deferred by the owner 2026-09-17 pending a ticket that authorises and audits one.';

    public const WAIVER_OWNER = 'Owner role (BR-P0-017)';

    /**
     * The sentence every closure refusal ends with.
     *
     * It says the waiver is NOT BUILT. It must never say or imply that no
     * waiver exists — the rule says one does, and a screen that contradicts the
     * rule is misleading the person reading it.
     */
    public const WAIVER_MESSAGE = 'BR-P0-017 allows an Owner to waive a failed closure control. That waiver is specified but not built yet, so there is currently no way to override this.';

    /* ── EVT-012 ─────────────────────────────────────────────────────── */

    /**
     * The idempotency key names `close_version`, which does not exist — D-107,
     * the same shape as D-65 (`approval_id`), where the ruling was: do not
     * invent a table.
     *
     * `closed` is TERMINAL, so the state machine IS the idempotency: a second
     * close cannot transition. Nothing is substituted for the missing version.
     */
    public const IDEMPOTENCY_GAP = 'EVT-012 keys on trip_id+close_version; no close_version and no versions table exist. D-107. Terminal state serves instead; nothing substituted.';

    /**
     * STT-012's side effect. `trip_profit_snapshots` is not a table
     * (SNG-TRN-018), so ProfitEngine has nothing to consume.
     *
     * The event is emitted anyway. A specified event is not dropped because its
     * consumer is late; that would make the gap invisible the day the consumer
     * arrives.
     */
    public const SNAPSHOT_PROFIT = 'not_built';

    public const EXCLUDED = [
        'profit snapshot'      => "STT-012's side effect. trip_profit_snapshots does not exist; SNG-TRN-018 is not built. EVT-012 is still emitted.",
        'waiver'               => self::WAIVER_DEFERRED,
        'settlement_pending'   => 'Step 9 places SETTLEMENT_PENDING between collection_pending and closed. Nothing gates it and trip_settlements does not exist. Declared and unreachable under the standing Step 9/Step 11 rule.',
        'reopening a closed trip' => '`closed` is SM-TRP\'s only terminal state and no document defines a reverse. A trip closed in error is a defect to raise, not a button.',
    ];
}
