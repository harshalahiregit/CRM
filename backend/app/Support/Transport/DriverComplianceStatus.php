<?php

namespace App\Support\Transport;

/**
 * Driver compliance status — STOS-CMP §23.
 *
 * A THIRD axis, distinct from the other two a driver carries:
 *   DriverStatus        "does this person drive for us at all"   (BO-009)
 *   DriverAvailability  "is this driver free right now"          (STOS-DB §44)
 *   this class          "are this driver's papers in order"      (CMP §23)
 *
 * Found missing by an audit on 2026-09-07 (gap G-2): §23 was never implemented
 * and never recorded as deferred, while the vehicle equivalent (FLEET §12) was
 * at least flagged as derived. This closes it.
 *
 * ── DERIVED, NOT STORED ───────────────────────────────────────────────────
 * Computed on demand from the licence and the driver's documents, matching how
 * vehicle compliance is treated. A stored column is wrong the morning after a
 * certificate lapses unless something swept overnight, and no P0 ticket owns a
 * sweep. Ruled by the owner 2026-09-07.
 *
 * ── WHAT §23 DOES NOT SAY ─────────────────────────────────────────────────
 * CMP §23 lists five values and defines none of them. Searched across all 42
 * documents:
 *
 *   EXPIRING      No threshold anywhere. CMP §18 offers configurable reminders
 *                 at 90/60/30/15/7 days; FLEET §13 offers 60/30/15/7. Both say
 *                 the exact interval belongs to the organization. 30 is the only
 *                 value present in BOTH lists, so it is the default here — a
 *                 value taken from the documents rather than invented. It is a
 *                 parameter, and SNG-TRN-009 step 5 will pass the tenant's own
 *                 policy (DB-020 transport_policies) instead.
 *
 *   BLOCKED       §23 gives no trigger. CMP §20/§21 make blocking a configurable
 *                 per-requirement rule, and Step 2 BO-009 already defines a
 *                 driver lifecycle value that means exactly "administratively
 *                 barred". Rather than invent a second, competing notion of
 *                 blocked, this maps to DriverStatus::BLOCKED. So the value is
 *                 real and reachable — sourced from the column that already
 *                 carries the fact.
 *
 *   UNDER_REVIEW  Genuinely unreachable. The token appears in CMP §11
 *                 (applicability), DOC §58 (billing pack), LSM §39 (leave) and
 *                 STOS-FIN — never in a driver-compliance context, and no
 *                 document state or event produces it. Declared so the enum
 *                 matches §23 and a later workflow can reach it without a
 *                 rename; nothing can currently return it. This is the same
 *                 "declare all, wire only what is reachable" discipline used by
 *                 VehicleStatus, TripStatus and DriverAvailability.
 *                 FLAGGED: §23 defines no producer for this state.
 *
 * ── ONE MORE LIMIT, STATED PLAINLY ────────────────────────────────────────
 * CMP §24 splits the work: "STOS-CMP determines what is required; STOS-DOC
 * manages the evidence lifecycle." The REQUIRED-document set is configurable and
 * has no home until step 5. So COMPLIANT here means "nothing on file has lapsed
 * and the licence is valid" — it does NOT yet mean "every required document is
 * present". A driver with no documents at all reads COMPLIANT once their licence
 * is valid. Step 5's policy closes that, and the eligibility service is where
 * the missing-document check belongs.
 */
final class DriverComplianceStatus
{
    public const COMPLIANT     = 'compliant';
    public const EXPIRING      = 'expiring';
    public const NON_COMPLIANT = 'non_compliant';
    public const BLOCKED       = 'blocked';
    public const UNDER_REVIEW  = 'under_review';

    /** CMP §23 in its own order. */
    public const ALL = [
        self::COMPLIANT, self::EXPIRING, self::NON_COMPLIANT,
        self::BLOCKED, self::UNDER_REVIEW,
    ];

    /** Values this ticket can actually produce. */
    public const REACHABLE = [
        self::COMPLIANT, self::EXPIRING, self::NON_COMPLIANT, self::BLOCKED,
    ];

    /** Declared to match §23; no producer exists. See the class docblock. */
    public const UNREACHABLE = [self::UNDER_REVIEW];

    /**
     * The only value shared by CMP §18 (90/60/30/15/7) and FLEET §13
     * (60/30/15/7). Overridden per tenant in SNG-TRN-009 step 5.
     */
    public const DEFAULT_EXPIRING_WINDOW_DAYS = 30;

    /** States that must not be given a trip (BR-P0-004). */
    public const BLOCKING = [self::NON_COMPLIANT, self::BLOCKED];

    public const LABELS = [
        self::COMPLIANT     => 'Compliant',
        self::EXPIRING      => 'Expiring',
        self::NON_COMPLIANT => 'Non-compliant',
        self::BLOCKED       => 'Blocked',
        self::UNDER_REVIEW  => 'Under review',
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /** BR-P0-004 — "critical document expired blocks assignment". */
    public static function blocksAssignment(string $status): bool
    {
        return in_array($status, self::BLOCKING, true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
