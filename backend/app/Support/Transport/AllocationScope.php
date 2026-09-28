<?php

namespace App\Support\Transport;

/**
 * SNG-TRN-009 — the approved scope of Vehicle/Driver Allocation.
 *
 * ── WHY THIS FILE EXISTS ──────────────────────────────────────────────────
 * The allocation requirement is described four different ways across the STOS
 * package, and the richest description is the one that is NOT approved. Without
 * a recorded ruling, the next person to read STOS-OPS §19 will reasonably
 * conclude that workload, proximity, tyres and genset belong in the eligibility
 * check. They do not. This class is the ruling, kept next to the code it binds.
 *
 * ── THE FOUR DESCRIPTIONS, AND WHICH ONE GOVERNS ──────────────────────────
 *   STT-004 (Step 11, LOCKED)      "Vehicle/driver valid" — one line.
 *   FRS TRP-P0-003/004 (Step 3)    double-booking; expired mandatory document;
 *                                  driver unavailable; licence validity; duty status.
 *   OPS §19/§25, FLEET §17/§18     9 driver / 12 vehicle factors incl. tyres,
 *                                  fuel, genset, workload, proximity. REFERENCE TIER.
 *   BR-ALLOC-001                   availability, location, RETURN STATUS, utilization,
 *                                  eligibility, compliance, maintenance status,
 *                                  EXPECTED OPERATING COST, service requirements.
 *
 * The last one is what most people quote, because it is the crispest. It is also
 * the only one with no standing: BR-ALLOC-001 exists in exactly ONE file in the
 * entire 42-document package — "Update before development.docx", the CR-001 memo
 * — and CR-001 was never applied to the frozen baseline. The strings
 * "return status" and "expected operating cost" appear nowhere else in the
 * package. Verified by full-text search across all 42 documents.
 *
 * Implementing it would be FORBID-001: "Claude may IMPLEMENT approved
 * requirements. It may not silently CHANGE approved product decisions."
 *
 * ── THE RULING (owner's decision, recorded 2026-09-07) ────────────────────
 * RTM §17 is authoritative. Its P0 rows are this ticket. Its P1 rows are not.
 * Scoring, weighting and manual override are deferred — but the eligibility
 * check is shaped so they can slot in later through transport_policies (DB-020)
 * without restructuring: every rule is a named, data-driven check carrying a
 * `required` flag, never a hard-coded branch.
 *
 * @see TransportPermission for the sibling ruling on PERM-001/002 gaps.
 */
final class AllocationScope
{
    /* ── IN SCOPE (RTM §17, P0) ──────────────────────────────────────── */

    /** Identify available vehicles — "Only eligible vehicles suggested". */
    public const PLN_002 = 'STOS-REQ-PLN-002';

    /** Identify available drivers — "Only eligible drivers suggested". */
    public const PLN_003 = 'STOS-REQ-PLN-003';

    /** Validate driver compliance before assignment — "Invalid driver blocked". */
    public const PLN_004 = 'STOS-REQ-PLN-004';

    /** Validate vehicle compliance before assignment — "Invalid vehicle blocked". */
    public const PLN_005 = 'STOS-REQ-PLN-005';

    /** Prevent double allocation — "Concurrent assignment controlled". */
    public const PLN_006 = 'STOS-REQ-PLN-006';

    /**
     * PLN-001 — "Plan vehicle requirement". Acceptance: "Required capacity
     * identified". P0.
     *
     * Added to scope on 2026-09-07 after an audit found it belonged to no list.
     * It is not a separate feature: FRS TRP-P0-003 already names "payload" as an
     * allocation input, and transport_vehicles.capacity_tonnes exists to be
     * compared against it. Matching a vehicle's capacity to what the order needs
     * IS vehicle eligibility, so it lands in the step-5 eligibility service
     * beside the compliance checks rather than anywhere new.
     */
    public const PLN_001 = 'STOS-REQ-PLN-001';

    public const IN_SCOPE = [
        self::PLN_001, self::PLN_002, self::PLN_003, self::PLN_004, self::PLN_005, self::PLN_006,
    ];

    /* ── OUT OF SCOPE (RTM §17, P1) — named so the boundary is testable ── */

    /** Controlled manual override. P1. Requires authorization + reason. */
    public const PLN_007 = 'STOS-REQ-PLN-007';

    /** Prevent favour-based allocation via configured rules/scoring. P1. */
    public const PLN_008 = 'STOS-REQ-PLN-008';

    /** Identify vehicle idle due to driver shortage. P0, but not allocation-time. */
    public const PLN_009 = 'STOS-REQ-PLN-009';

    /** Identify driver shortage requirement. P1. */
    public const PLN_010 = 'STOS-REQ-PLN-010';

    public const DEFERRED = [self::PLN_007, self::PLN_008, self::PLN_009, self::PLN_010];

    /**
     * Every row of RTM §17, and where each one went.
     *
     * This map exists because an audit on 2026-09-07 found PLN-001, PLN-009 and
     * PLN-010 sitting in no list at all — neither built nor deferred, which is
     * precisely how a P0 requirement disappears. RTM §17 has TEN rows; all ten
     * are now accounted for, and a test asserts that stays true.
     */
    public const RTM_17_DISPOSITION = [
        self::PLN_001 => 'in_scope',
        self::PLN_002 => 'in_scope',
        self::PLN_003 => 'in_scope',
        self::PLN_004 => 'in_scope',
        self::PLN_005 => 'in_scope',
        self::PLN_006 => 'in_scope',
        self::PLN_007 => 'deferred',
        self::PLN_008 => 'deferred',
        self::PLN_009 => 'deferred',
        self::PLN_010 => 'deferred',
    ];

    /**
     * Requirements this ticket must NOT implement, and why.
     *
     * Kept as data rather than prose so a reviewer can diff intent against
     * behaviour, and so the reason survives longer than anyone's memory of
     * the conversation that produced it.
     */
    public const EXCLUDED = [
        'BR-ALLOC-001' => 'Unratified. CR-001 memo only; never applied to the frozen baseline (Blocker 4).',
        'scoring'      => 'PLN-008 is P1. No weights, no ranking, no recommendation score in this ticket.',
        'override'     => 'PLN-007 is P1. No manual override path; a blocked allocation is simply refused.',
        'location'     => 'Needs GPS telemetry — SNG-TRN-020, P1. No location input exists to check.',
        'cost'         => 'Expected operating cost is BR-ALLOC-001 only, and has no cost model until SNG-TRN-012/018.',
        'tyres/fuel/genset/urea' => 'STOS-FLEET reference tier. No P0 ticket owns these domains.',
        'workload/proximity/performance' => 'STOS-OPS §19 reference tier. Inputs to scoring, which is P1.',
        'trailer'      => 'Ticket 003 names it, but no trailer entity exists in Step 11. Raised as a registry gap.',
        // Ruled 2026-09-07. Both are RTM §17 rows, so they are recorded here
        // rather than left unlisted — but neither is an allocation-time check.
        'PLN-009'      => 'Idle vehicle due to driver shortage. Fleet monitoring, not an assignment gate; belongs to SNG-TRN-019 Control Room. P0 there, not here.',
        'PLN-010'      => 'Driver shortage requirement. P1, and STOS-DB §118 places recruitment outside Transport. Control Room / reporting.',
        // Ruled 2026-09-07 while building step 5. STOS-FLEET §17 lists nine
        // vehicle-eligibility inputs and STOS-OPS §19 lists nine driver ones;
        // these are the ones with no data model on either side. Recorded so an
        // absent check is a decision rather than an oversight.
        'vehicle type'      => 'FLEET §17. The order carries free-text service_type and the vehicle free-text vehicle_type; no document specifies a mapping between them. Any matching rule would be invented and would falsely block legitimate vehicles.',
        'service requirement' => 'FLEET §17. order.special_requirements is free text; no structured service-capability model exists.',
        'route'             => 'FLEET §17, OPS §19/§25. No lane or route-capability model exists on vehicle or driver.',
        'customer requirement' => 'FLEET §17, OPS §19. No customer-requirement model exists.',
        'branch'            => 'STOS-TEST §32 names a "wrong branch" driver case, but transport_drivers has no branch column, trips and orders have nothing to match against, and no document states the constraint as a rule. Two of three pieces missing.',
    ];

    /* ── The rules that DO bite, traced to their business-rule IDs ────── */

    /** BR-P0-003 — vehicle cannot have overlapping active trips. Hard/Critical. */
    public const BR_VEHICLE_OVERLAP = 'BR-P0-003';

    /** BR-P0-004 — driver unavailable or critical document expired blocks assignment. Hard/Critical. */
    public const BR_DRIVER_BLOCKED = 'BR-P0-004';

    /** QA-003 — expired vehicle document must block allocation with an actionable message. */
    public const QA_EXPIRED_DOCUMENT = 'QA-003';
}
