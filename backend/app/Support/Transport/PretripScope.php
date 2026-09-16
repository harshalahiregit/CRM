<?php

namespace App\Support\Transport;

/**
 * SNG-TRN-010 — the approved scope of Pre-trip Checks.
 *
 * ── WHY THIS FILE EXISTS ──────────────────────────────────────────────────
 * Six documents describe a pre-dispatch checklist, no two of them agree on what
 * is on it, and the ticket itself carries a one-sentence acceptance criterion
 * ("Checklist completion is time/user stamped") that settles none of it. Left
 * unrecorded, the next reader of STOS-OPS §35 will reasonably conclude that
 * brakes, lights and engine belong in this ticket. They do not — not because
 * they are unimportant, but because nothing in the package stores them.
 *
 * Same discipline, same file shape, same reason as AllocationScope.
 *
 * ── THE SIX DESCRIPTIONS, AND WHICH ONE GOVERNS ───────────────────────────
 *   RTM §20/§29 (Step 1)      OPS-004 checklist, OPS-005 documents, OPS-008
 *                             dispatch record, CMP-006 block non-compliant.
 *                             FOUR P0 ROWS. This is the authority.
 *   FRS TRP-P0-005 (Step 3)   vehicle inspection; tyres; documents; safety;
 *                             trailer; reefer/genset. Supervisor sign-off.
 *   OPS §28 (reference)       5 categories / 14 items — the richest list.
 *   OPS §35 (reference)       tyres; lights; brakes; fuel; engine; Genset;
 *                             temperature; documents; safety equipment.
 *                             Hedged in the source itself as "Possible checks".
 *   BRWM §12/§13              BRW-046..053. 13 dispatch-checklist inputs.
 *   CMP §157                  Customer Requirement + Driver + Vehicle +
 *                             Documents + Training + Service Requirement.
 *
 * The RTM is the authority for scope, exactly as it was for ticket 009, and it
 * frames this ticket narrowly: a dispatch readiness checklist driven by document
 * and compliance validation. The PHYSICAL inspection — tyres, brakes, lights,
 * fuel, engine, genset, temperature — has no RTM requirement row anywhere, and
 * no data model in any of the twenty canonical DB entities.
 *
 * ── THE UNRATIFIED SOURCES (the BR-ALLOC-001 trap, this ticket's version) ──
 * Two documents describe this feature more vividly than any approved one:
 *
 *   "Product Brief Video.docx" Scene 5 — "Dispatch Readiness: 8/10" over a
 *   checklist of LR, DO, Gate Pass, Port Entry, POD and Billing Support
 *   Document. It is a video script. It also puts POD and billing documents
 *   into a PRE-dispatch gate, which contradicts DOC §14's own stage list.
 *
 *   "Update before development.docx" — "I recommend making Document Handover
 *   Chain a formal P0 workflow." A recommendation, in the same unratified memo
 *   that produced BR-ALLOC-001 and was never applied to the frozen baseline.
 *
 * Neither is built from. Both are recorded here so the omission is a decision.
 *
 * ── THE RULINGS (owner's decisions, recorded 2026-09-09) ──────────────────
 * Q1  State machine: Step 9's two edges, allocated → pretrip_ok → dispatched.
 *     See STATE_* below — the ruling is data here, not prose.
 * Q2  Physical inspection: declare the checks, leave them unreachable.
 * Q3  Documents: reuse the existing transport_documents evaluation. No new
 *     trip-level document engine.
 * Q4  Photo evidence: out of scope; no upload capability exists in Transport.
 * Q5  Document handover: declare the check, leave it unreachable.
 *
 * @see AllocationScope   the sibling ruling for SNG-TRN-009.
 * @see PretripCheckKey   the check vocabulary these dispositions govern.
 */
final class PretripScope
{
    /* ── IN SCOPE (RTM §20 and §29, P0) ──────────────────────────────── */

    /**
     * Generate dispatch readiness checklist. OPS/DOC. P0.
     * Acceptance: "Missing requirements identified".
     */
    public const OPS_004 = 'STOS-REQ-OPS-004';

    /**
     * Validate documents before dispatch. DOC/CMP. P0.
     * Acceptance: "Mandatory gaps block/flag dispatch".
     */
    public const OPS_005 = 'STOS-REQ-OPS-005';

    /**
     * Block non-compliant resource where required. CMP/OPS. P0.
     * Acceptance: "Dispatch control". This is CMP §159's dispatch gate.
     */
    public const CMP_006 = 'STOS-REQ-CMP-006';

    public const IN_SCOPE = [self::OPS_004, self::OPS_005, self::CMP_006];

    /* ── OUT OF SCOPE, named so the boundary is testable ──────────────── */

    /** Manage gate pass. P1. */
    public const OPS_006 = 'STOS-REQ-OPS-006';

    /** Manage port entry slip. P1. */
    public const OPS_007 = 'STOS-REQ-OPS-007';

    /**
     * Record dispatch. P0. Acceptance: "Dispatch timestamp/status recorded".
     *
     * DEFERRED, and this is the one deferral in this file that costs something,
     * so the reasoning is written out in full.
     *
     * Under Q1's ruling the trip reaches `dispatched` by a SECOND edge,
     * pretrip_ok → dispatched, and that edge is dispatch confirmation:
     * FRS TRP-P0-006, which requires ETD, ETA/TAT, pickup contact, destination
     * and instructions, freezes those fields on release, and versions any later
     * change. None of those five fields exists on transport_trips. BRW-050 adds
     * side effects on top (vehicle → In Operation, driver → On Trip).
     *
     * No ticket in the register owns any of it — the register runs 010 Pre-trip
     * → 011 Advance → 012 Cost, and STT-006 (dispatched → in_transit) is
     * likewise ownerless. Recorded as D-18.
     *
     * Building it here would mean inventing five columns and a versioning rule
     * that no approved document specifies. What this ticket DOES satisfy is
     * STT-005's stated side effect, "Record departure readiness": pretrip_ok is
     * reached with an actor and a timestamp against every check. The dispatch
     * timestamp itself waits for the ticket that owns the fields.
     */
    public const OPS_008 = 'STOS-REQ-OPS-008';

    /** Handle trip cancellation. P1. */
    public const OPS_011 = 'STOS-REQ-OPS-011';

    /** Handle driver abandonment / operational incident. P1. */
    public const OPS_013 = 'STOS-REQ-OPS-013';

    /**
     * Support authorized compliance override. P1.
     *
     * The third P1 override row in this package, after PLN-007 (allocation) and
     * BRW-049 (dispatch). All three are deferred together and consistently:
     * READINESS_OVERRIDE_REQUIRED and PretripResult::OVERRIDE are declared and
     * left unwired so the vocabulary is fixed before the ticket that needs it.
     */
    public const CMP_007 = 'STOS-REQ-CMP-007';

    public const DEFERRED = [
        self::OPS_006, self::OPS_007, self::OPS_008,
        self::OPS_011, self::OPS_013, self::CMP_007,
    ];

    /**
     * Every row of RTM §20, and where each one went.
     *
     * Fourteen rows. The audit that produced AllocationScope::RTM_17_DISPOSITION
     * found three P0 requirements sitting in no list at all, which is exactly how
     * a P0 requirement disappears. The same map, and the same guarding test,
     * exists here so that cannot recur.
     */
    public const RTM_20_DISPOSITION = [
        'STOS-REQ-OPS-001' => 'done_earlier',   // Order → trip. SNG-TRN-007.
        'STOS-REQ-OPS-002' => 'done_earlier',   // Assign vehicle. SNG-TRN-009.
        'STOS-REQ-OPS-003' => 'done_earlier',   // Assign driver.  SNG-TRN-009.
        self::OPS_004      => 'in_scope',
        self::OPS_005      => 'in_scope',
        self::OPS_006      => 'deferred',       // P1.
        self::OPS_007      => 'deferred',       // P1.
        self::OPS_008      => 'deferred',       // P0 — see the constant. D-18.
        'STOS-REQ-OPS-009' => 'done_earlier',   // Track trip status. SNG-TRN-007.
        'STOS-REQ-OPS-010' => 'later_ticket',   // Record delivery. SNG-TRN-013/014.
        self::OPS_011      => 'deferred',       // P1.
        'STOS-REQ-OPS-012' => 'later_ticket',   // Breakdown. SNG-TRN-013.
        self::OPS_013      => 'deferred',       // P1.
        'STOS-REQ-OPS-014' => 'later_ticket',   // Urgent trips. OrderPriority exists; no P0 gate here.
    ];

    /**
     * Every row of RTM §29, and where each one went. Nine rows.
     *
     * Only CMP-006 is this ticket's. The rest are the Compliance module proper,
     * which no ticket in the register owns — CMP-001/003/005/008 are all P0 and
     * all ownerless. Recorded rather than quietly ignored.
     */
    public const RTM_29_DISPOSITION = [
        'STOS-REQ-CMP-001' => 'no_ticket',      // Compliance master. P0, ownerless.
        'STOS-REQ-CMP-002' => 'deferred',       // ISO. P1.
        'STOS-REQ-CMP-003' => 'no_ticket',      // GDP. P0, ownerless.
        'STOS-REQ-CMP-004' => 'deferred',       // CTPAT. P1.
        'STOS-REQ-CMP-005' => 'done_earlier',   // Track expiry. SNG-TRN-003/004.
        self::CMP_006      => 'in_scope',
        self::CMP_007      => 'deferred',       // P1 override.
        'STOS-REQ-CMP-008' => 'done_earlier',   // Compliance evidence. transport_documents.
        'STOS-REQ-CMP-009' => 'deferred',       // Reports. P1.
    ];

    /**
     * STOS-OPS §28's checklist, item by item, and what this ticket does with it.
     *
     * §28 is the richest description of the checklist in the package and the
     * closest thing it has to a specification, so every one of its fourteen items
     * is dispositioned here and a test asserts the map stays complete.
     *
     * 'built'       — generated, evaluated, and able to block.
     * 'declared'    — the key exists in PretripCheckKey and is never generated,
     *                 because nothing in the package stores what it would read.
     * 'cross_ref'   — the requirement is met, by a check listed elsewhere here.
     *
     * §28 calls these "Minimum categories", so the set is a floor rather than a
     * ceiling; PretripCheckKey carries the further items named by BRW-047,
     * OPS §35, CMP §157 and FRS TRP-P0-005.
     */
    public const OPS_28_DISPOSITION = [
        // Commercial
        'commercial.order_approved'   => 'built',
        'commercial.rate_available'   => 'declared',
        // Driver
        'driver.assigned'             => 'built',
        'driver.documents_valid'      => 'built',
        'driver.training'             => 'declared',
        // Vehicle
        'vehicle.assigned'            => 'built',
        'vehicle.compliance_valid'    => 'built',
        'vehicle.maintenance'         => 'declared',
        'vehicle.tyres'               => 'declared',
        // Reefer
        'reefer.genset'               => 'declared',
        'reefer.temperature'          => 'declared',
        'reefer.equipment'            => 'declared',
        // Documents
        'documents.required_ready'    => 'cross_ref',
        'documents.handover'          => 'declared',
    ];

    /**
     * Requirements this ticket must NOT implement, and why.
     *
     * Data rather than prose, so a reviewer can diff intent against behaviour and
     * the reason outlives everyone's memory of the conversation that produced it.
     */
    public const EXCLUDED = [
        // ── Ruled by the owner, 2026-09-09 ────────────────────────────────
        'physical inspection' => 'Q2. Tyres, brakes, lights, fuel, engine, genset, temperature and safety equipment are named by OPS §35, BRWM §13 and FRS TRP-P0-005, but have no RTM requirement row and no column in any of the 20 canonical DB entities. Declared as check keys, never generated. Same treatment as trailer/GPS in ticket 009.',
        'trip document engine' => 'Q3. DOC §12-20 describes customer/service-driven required document sets against trip_documents (DB-009) — a whole unbuilt engine. This ticket reuses the transport_documents evaluation the eligibility services already perform.',
        'photo evidence'      => 'Q4. Required by FRS TRP-P0-005, UAT-004 and UX §94, but Transport has NO file-upload capability at all: transport_documents.file_path exists and has never been populated. Deferred with the capability. Recorded as D-22.',
        'document handover'   => 'Q5. RTM DOC-005 is P0 and OPS §32 / BRW-059-060 describe a custody workflow with OTP, signature and acknowledgement. No ticket owns it, no entity stores it. Declared, never generated.',

        // ── Deferred by priority, consistent with ticket 009 ──────────────
        'override'            => 'CMP-007, BRW-049 and PLN-007 are all P1. A blocked trip is simply blocked; there is no authorized-override path. OVERRIDE_REQUIRED and PretripResult::OVERRIDE are declared and unwired.',
        'gate pass'           => 'RTM OPS-006 is P1, and no entity stores a gate pass. Sprint Plan §33 groups it with dispatch rather than with the pre-trip checklist.',
        'port entry slip'     => 'RTM OPS-007 is P1. Port entry belongs to the container/port workflow (OPS §40-41), which no P0 ticket owns.',
        'offline capture'     => 'The ticket DoD asks for offline tests, but offline is SNG-TRN-026 — P1, Backlog, Sprint S13 — and STOS-DEV §118 states offline "should only be developed where explicitly required", naming driver workflows as candidates for FUTURE work. STOS-TEST §152 is conditional on offline existing. Recorded as D-19.',
        'driver self-service' => 'OPS §33/§34 describe a driver app completing the inspection. transport_drivers links only to an optional hr_employee_id and has no user account, so no driver can authenticate. Checklists are completed by staff on the driver\'s behalf. Belongs with SNG-TRN-026.',

        // ── No data model on either side. Same finding as ticket 009 ──────
        'customer requirement' => 'BRW-047 and CMP §157 both name it. No customer-requirement model exists. Identical exclusion to AllocationScope.',
        'service requirement'  => 'CMP §157. order.special_requirements is free text; no structured service-capability model exists.',
        'trailer'              => 'FRS TRP-P0-005 names it. No trailer entity exists in Step 11.',
        'required approvals'   => 'BRW-047. No approval-request entity exists in Transport; Step 5 lists a generic approval_requests table that Step 11 does not carry.',
        'safety equipment'     => 'OPS §35, FRS TRP-P0-005. No equipment model on the vehicle.',
        'fuel'                 => 'BRW-047, OPS §35. No fuel model until SNG-TRN-012.',
        'rate available'       => 'OPS §28 Commercial. transport_rates (DB-016) belongs to SNG-TRN-005, which is not built. Declared, never generated.',
        'training'             => 'OPS §28 Driver, CMP §157. No training or certification model exists on transport_drivers.',
        'maintenance'          => 'OPS §28 Vehicle, BRW-044/047. No maintenance entity exists in Step 11. Note VehicleStatus::MAINTENANCE already keeps a vehicle under maintenance out of allocation, so the operational intent is partly served without the model.',
    ];

    /* ── Q1: THE STATE-MACHINE RULING, AS DATA ────────────────────────── */

    /**
     * The ruling, recorded 2026-09-09 by the owner.
     *
     * Step 9's Trip machine has a state between allocated and dispatched:
     *
     *   Step 9   APPROVED → ALLOCATED → PRETRIP_OK → DISPATCHED   (two edges)
     *   Step 11  approved → allocated → dispatched                (one, STT-005)
     *
     * Step 9 is the higher authority and was ruled definitive when TripStatus was
     * written for SNG-TRN-007; PRETRIP_OK has been declared and dead ever since.
     * This ticket makes it live. Ticket 009 never had to choose, because
     * approved → allocated is identical in both machines — 010 is the first
     * ticket where the two documents genuinely diverge.
     *
     * Neither of Step 9's two edges has an STT row of its own. STT-005 is the
     * only registry transition covering this ground, and under the ruling its
     * work splits across the two edges: its precondition ("All checks passed")
     * and its side effect ("Record departure readiness") land on the first edge,
     * which this ticket owns; its destination lands on the second, which nobody
     * owns. See OPS_008 above and D-18.
     */
    public const STATE_RULING_SOURCE = 'Step 9 Master Product Constitution — State_Machines, Trip';

    /** The edge SNG-TRN-010 implements and guards. */
    public const STATE_EDGE_OWNED = TripStatus::ALLOCATED.'->'.TripStatus::PRETRIP_OK;

    /**
     * The edge Step 9 declares that this ticket does NOT wire.
     *
     * Dispatch confirmation, FRS TRP-P0-006. Five fields that do not exist, a
     * field-freeze rule, and BRW-050's resource side effects — with no ticket.
     */
    public const STATE_EDGE_DEFERRED = TripStatus::PRETRIP_OK.'->'.TripStatus::DISPATCHED;

    /** The Step 11 transition whose preconditions this ticket implements. */
    public const STT_005 = 'STT-005';

    /* ── The rules that DO bite, traced to their business-rule IDs ────── */

    /** BRW-046 — "Vehicle cannot dispatch until all mandatory dispatch checks pass." */
    public const BRW_DISPATCH_READINESS = 'BRW-046';

    /** BRW-047 — the dispatch checklist itself; 13 named inputs. */
    public const BRW_DISPATCH_CHECKLIST = 'BRW-047';

    /** BRW-048 — "If dispatch fails, Sangoe must display exact reason." */
    public const BRW_BLOCK_REASON = 'BRW-048';

    /** BRW-051 — pre-trip inspection required before dispatch. */
    public const BRW_MANDATORY_INSPECTION = 'BRW-051';

    /** BRW-052 — critical failure blocks; non-critical warns, per policy. */
    public const BRW_INSPECTION_FAILURE = 'BRW-052';

    /** UAT-004 — "100% mandatory checks enforced". Field UAT gate. */
    public const UAT_004 = 'UAT-004';

    /**
     * S6-004's acceptance line, which is the most implementable sentence the
     * package contains about this ticket and the reason Step 3 is a policy step:
     * "Mandatory checks configurable; failed items block dispatch when policy
     * says so."
     */
    public const S6_004 = 'S6-004';
}
