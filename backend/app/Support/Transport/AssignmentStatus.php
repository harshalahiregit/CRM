<?php

namespace App\Support\Transport;

/**
 * Assignment lifecycle — STOS-LSM §44 "Vehicle Allocation Lifecycle".
 *
 * ── WHERE THIS COMES FROM, AND WHAT IT COSTS ──────────────────────────────
 * Step 11 defines FOUR state machines — SM-ORD, SM-TRP, SM-ADV, SM-EXC — and
 * none of them is for assignments. DB-003 `trip_assignments` is a LOCKED registry
 * entity with zero specified columns (defect D-3) and zero specified states.
 *
 * The only assignment lifecycle anywhere in the 42-document package is LSM §44,
 * which is reference tier:
 *
 *   REQUEST → ELIGIBILITY_CHECK → RECOMMENDATION → APPROVAL_IF_REQUIRED
 *           → ASSIGNED → CONFIRMED → ACTIVE → RELEASED
 *
 * All eight are declared; only two are reachable from this ticket. Same
 * discipline as TripStatus, VehicleStatus and DriverAvailability — a later ticket
 * adds a transition rather than renaming a stored value, and no row has to be
 * migrated to introduce a state that was always going to exist.
 *
 *   Owned by SNG-TRN-009 step 6:  assigned, released
 *   Owned by P1 (PLN-007/008):    recommendation, approval_if_required
 *   Owned by pre-trip / dispatch: confirmed, active
 *   Never persisted:              request, eligibility_check — these describe
 *                                 the act of asking, which happens before a row
 *                                 exists. Declared for completeness only.
 *
 * ── ACTIVE_STATES IS LOAD-BEARING ─────────────────────────────────────────
 * It is not a convenience list. The double-booking guard (BR-P0-003) is a
 * database unique index over a generated column that is non-null exactly when
 * status is one of these. Adding a value here without thinking widens what
 * counts as "occupied"; removing one lets a vehicle be booked twice.
 */
final class AssignmentStatus
{
    /** Declared for completeness; never persisted — see the class docblock. */
    public const REQUEST              = 'request';
    public const ELIGIBILITY_CHECK    = 'eligibility_check';
    /** P1 — scoring (PLN-008). */
    public const RECOMMENDATION       = 'recommendation';
    /** P1 — override/approval (PLN-007). */
    public const APPROVAL_IF_REQUIRED = 'approval_if_required';
    /** The state SNG-TRN-009 creates. */
    public const ASSIGNED             = 'assigned';
    /** Pre-trip confirmed it. SNG-TRN-010. */
    public const CONFIRMED            = 'confirmed';
    /** Dispatched and running. */
    public const ACTIVE               = 'active';
    /** Terminal. The vehicle and driver are free again. */
    public const RELEASED             = 'released';

    /** LSM §44 in its own order. */
    public const ALL = [
        self::REQUEST, self::ELIGIBILITY_CHECK, self::RECOMMENDATION,
        self::APPROVAL_IF_REQUIRED, self::ASSIGNED, self::CONFIRMED,
        self::ACTIVE, self::RELEASED,
    ];

    public const INITIAL  = self::ASSIGNED;
    public const TERMINAL = [self::RELEASED];

    /**
     * States in which the vehicle and driver are considered occupied.
     *
     * BR-P0-003 ("Vehicle cannot have overlapping active trips", Hard/Critical),
     * STOS-DB §198/§199, and STOS-TEST §33's "assigned elsewhere" case all key
     * off this. It is enforced in the database, not only in PHP — see the
     * migration's generated columns.
     */
    public const ACTIVE_STATES = [self::ASSIGNED, self::CONFIRMED, self::ACTIVE];

    /** Transitions this ticket owns. Everything else belongs to a later ticket. */
    public const TRANSITIONS = [
        self::ASSIGNED => [self::RELEASED],
    ];

    public const LABELS = [
        self::REQUEST              => 'Requested',
        self::ELIGIBILITY_CHECK    => 'Eligibility check',
        self::RECOMMENDATION       => 'Recommendation',
        self::APPROVAL_IF_REQUIRED => 'Awaiting approval',
        self::ASSIGNED             => 'Assigned',
        self::CONFIRMED            => 'Confirmed',
        self::ACTIVE               => 'Active',
        self::RELEASED             => 'Released',
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE_STATES, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
