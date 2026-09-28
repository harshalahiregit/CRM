<?php

namespace App\Support\Transport;

/**
 * Vehicle operational status — SNG-TRN-003 (DB-004 `vehicles`).
 *
 * ── SOURCE AND ITS LIMITS ─────────────────────────────────────────────────
 * Step 11 declares NO enum for vehicle status. ENUM-001…008 cover trip,
 * advance, exception, expense, document, risk and viability — nothing here.
 * The 13 values below come from STOS-FLEET §7, which labels them "Recommended
 * states", i.e. reference tier. Recorded as registry defect D-4.
 *
 * ── WHY ALL 13 ARE DECLARED WHEN ONLY SOME ARE REACHABLE ──────────────────
 * The same choice TripStatus makes: declare the whole lifecycle, allow only the
 * transitions this ticket actually owns. A later ticket adding maintenance then
 * adds a transition rather than renaming a stored value, and nobody has to
 * migrate rows to introduce a state that was always going to exist.
 *
 * Reachable here (vehicle master lifecycle):  new, available, suspended, sold, retired
 * Owned by SNG-TRN-009 (allocation):          allocated
 * Owned by dispatch/execution tickets:        in_transit, idle
 * Owned by no P0 ticket — declared only:      maintenance_due, under_maintenance,
 *                                             breakdown, accident, compliance_blocked
 *
 * compliance_blocked deserves a note. FLEET §14 says an expired critical
 * document makes a vehicle COMPLIANCE_BLOCKED. This module does NOT store that
 * conclusion: compliance is derived from transport_documents at the moment it is
 * asked, because a stored flag is wrong the day after a certificate lapses and
 * nobody ran the sweep. The value is declared so a future scheduled job can
 * materialise it if the business wants a visible badge; the eligibility check
 * itself never reads it.
 */
final class VehicleStatus
{
    public const NEW                = 'new';
    public const AVAILABLE          = 'available';
    public const ALLOCATED          = 'allocated';
    public const IN_TRANSIT         = 'in_transit';
    public const IDLE               = 'idle';
    public const MAINTENANCE_DUE    = 'maintenance_due';
    public const UNDER_MAINTENANCE  = 'under_maintenance';
    public const BREAKDOWN          = 'breakdown';
    public const COMPLIANCE_BLOCKED = 'compliance_blocked';
    public const ACCIDENT           = 'accident';
    public const SUSPENDED          = 'suspended';
    public const SOLD               = 'sold';
    public const RETIRED            = 'retired';

    public const ALL = [
        self::NEW, self::AVAILABLE, self::ALLOCATED, self::IN_TRANSIT, self::IDLE,
        self::MAINTENANCE_DUE, self::UNDER_MAINTENANCE, self::BREAKDOWN,
        self::COMPLIANCE_BLOCKED, self::ACCIDENT, self::SUSPENDED, self::SOLD,
        self::RETIRED,
    ];

    /** A vehicle is born uncommissioned. FLEET §8 forbids typing it straight to Available. */
    public const INITIAL = self::NEW;

    public const TERMINAL = [self::SOLD, self::RETIRED];

    /**
     * Transitions THIS ticket owns. Deliberately narrow.
     *
     * FLEET §8: "Vehicle status must be driven by business events. Users should
     * not freely type 'Available' without satisfying required conditions." Two of
     * the three conditions it names — unresolved critical maintenance, failed
     * safety inspection — belong to domains no P0 ticket owns, so they cannot be
     * enforced here. The third, expired compliance, is enforced at the point it
     * matters (allocation, SNG-TRN-009 step 5) rather than at commissioning.
     * Commissioning is therefore permitted, and the gate that actually protects
     * a trip lives on the allocation path. Recorded so the gap is deliberate.
     */
    public const TRANSITIONS = [
        self::NEW       => [self::AVAILABLE, self::RETIRED],
        // ALLOCATED added by SNG-TRN-009 step 6. FLEET §7 declares the state and
        // §15 says availability must consider "current trip; allocation" — so a
        // vehicle out on a trip must not keep reading Available in the fleet
        // list. Written only by AllocationService, never by the master screen.
        self::AVAILABLE => [self::ALLOCATED, self::SUSPENDED, self::SOLD, self::RETIRED],
        self::ALLOCATED => [self::AVAILABLE],
        self::SUSPENDED => [self::AVAILABLE, self::SOLD, self::RETIRED],
    ];

    /** Statuses a vehicle may hold and still be considered for a trip (PLN-002). */
    public const ALLOCATABLE = [self::AVAILABLE, self::IDLE];

    /**
     * Values only AllocationService and dispatch may set — see the matching
     * constant on DriverAvailability for the reasoning.
     */
    public const ALLOCATION_OWNED = [self::ALLOCATED, self::IN_TRANSIT];

    public const LABELS = [
        self::NEW                => 'New',
        self::AVAILABLE          => 'Available',
        self::ALLOCATED          => 'Allocated',
        self::IN_TRANSIT         => 'In transit',
        self::IDLE               => 'Idle',
        self::MAINTENANCE_DUE    => 'Maintenance due',
        self::UNDER_MAINTENANCE  => 'Under maintenance',
        self::BREAKDOWN          => 'Breakdown',
        self::COMPLIANCE_BLOCKED => 'Compliance blocked',
        self::ACCIDENT           => 'Accident',
        self::SUSPENDED          => 'Suspended',
        self::SOLD               => 'Sold',
        self::RETIRED            => 'Retired',
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
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
