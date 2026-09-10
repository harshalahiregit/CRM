<?php

namespace App\Support\Transport;

/**
 * Driver operational availability — "is this driver free right now?"
 *
 * ── FIVE SPECIFIED SETS, AND WHY THIS ONE ─────────────────────────────────
 * The package specifies driver availability five different ways:
 *   STOS-DB §44   AVAILABLE, ON_TRIP, ON_LEAVE, ABSENT, SUSPENDED, UNAVAILABLE
 *   STOS-LSM §40  Available, Assigned, On Trip, Leave, Unavailable, Suspended,
 *                 Training, Under Investigation, Inactive
 *   STOS-BRM BR-045   available, assigned, leave, unavailable, suspended, inactive
 *   RTM DRV-003       "Available/leave/unavailable states"
 *   Step 2 BO-009     Active/Inactive/Blocked   (a different axis — see DriverStatus)
 * Step 11 declares no driver enum at all (registry defect D-4), so every one of
 * these is reference tier and none outranks the others.
 *
 * Ruled by the owner on 2026-09-07: STOS-DB §44 as the base, PLUS ASSIGNED.
 *
 * ASSIGNED is the one value §44 omits and the other three sets all carry, and its
 * absence is not cosmetic. A driver booked onto tomorrow's trip is not ON_TRIP —
 * they have not left yet — but they are certainly not AVAILABLE either. Without a
 * state for "reserved", BRW-028 ("only drivers with AVAILABLE status may be
 * recommended") would happily recommend a driver who is already spoken for, and
 * PLN-006 double-booking prevention would rest entirely on scanning
 * trip_assignments. transport_vehicles already draws the same distinction with
 * ALLOCATED versus IN_TRANSIT; drivers now match.
 *
 * ── DECLARE ALL, WIRE ONLY WHAT THIS TICKET OWNS ──────────────────────────
 * Same discipline as TripStatus and VehicleStatus.
 *   Owned by SNG-TRN-004 (this ticket): the master-admin moves — a supervisor
 *     recording that a driver is on leave, absent, suspended or otherwise not
 *     usable, and bringing them back.
 *   Owned by SNG-TRN-009 (allocation):  available → assigned, assigned → available
 *   Owned by dispatch/execution:        assigned → on_trip, on_trip → available
 * Those transitions are deliberately absent below so nothing can reach them by
 * accident before the ticket that enforces their preconditions exists.
 */
final class DriverAvailability
{
    public const AVAILABLE   = 'available';
    /** Reserved for a trip but not yet driving. Not in §44 — see class docblock. */
    public const ASSIGNED    = 'assigned';
    public const ON_TRIP     = 'on_trip';
    public const ON_LEAVE    = 'on_leave';
    /** Unplanned absence, as distinct from approved leave. §44 only. */
    public const ABSENT      = 'absent';
    public const SUSPENDED   = 'suspended';
    public const UNAVAILABLE = 'unavailable';

    public const ALL = [
        self::AVAILABLE, self::ASSIGNED, self::ON_TRIP, self::ON_LEAVE,
        self::ABSENT, self::SUSPENDED, self::UNAVAILABLE,
    ];

    public const INITIAL = self::AVAILABLE;

    /**
     * The only value BRW-028 lets a driver be recommended from:
     * "Only drivers with AVAILABLE status may be automatically recommended
     *  for allocation."
     */
    public const ALLOCATABLE = [self::AVAILABLE];

    /** States meaning "spoken for" — used by SNG-TRN-009 to detect double-booking. */
    public const ENGAGED = [self::ASSIGNED, self::ON_TRIP];

    /**
     * Values only AllocationService and dispatch may set.
     *
     * The enum declares available → assigned because allocation genuinely makes
     * that move, but TransportDriverService refuses it: a supervisor marking a
     * driver "Assigned" from the master screen would reserve them with no
     * assignment row behind it, and the double-booking index would never see it.
     * A state that means "on a trip" must only ever be written by the thing that
     * put them on one.
     */
    public const ALLOCATION_OWNED = [self::ASSIGNED, self::ON_TRIP];

    /**
     * Transitions SNG-TRN-004 owns. See the class docblock for what is withheld.
     *
     * Every non-available state can return to available, because the alternative
     * is a driver who came back from leave and cannot be marked usable without a
     * database edit. SUSPENDED returns too — BRW-081 makes suspension an outcome
     * of an incident review, and reviews conclude.
     */
    public const TRANSITIONS = [
        // ASSIGNED added by SNG-TRN-009 step 6 — the reservation this enum was
        // extended for. Written only by AllocationService; the master screen
        // still cannot reach it, which is what keeps a supervisor from marking a
        // driver reserved without an assignment behind it.
        self::AVAILABLE   => [self::ASSIGNED, self::ON_LEAVE, self::ABSENT, self::SUSPENDED, self::UNAVAILABLE],
        self::ASSIGNED    => [self::AVAILABLE],
        self::ON_LEAVE    => [self::AVAILABLE, self::ABSENT, self::SUSPENDED, self::UNAVAILABLE],
        self::ABSENT      => [self::AVAILABLE, self::ON_LEAVE, self::SUSPENDED, self::UNAVAILABLE],
        self::SUSPENDED   => [self::AVAILABLE, self::UNAVAILABLE],
        self::UNAVAILABLE => [self::AVAILABLE, self::ON_LEAVE, self::ABSENT, self::SUSPENDED],
        // on_trip still has no outbound transition here — dispatch owns it.
    ];

    public const LABELS = [
        self::AVAILABLE   => 'Available',
        self::ASSIGNED    => 'Assigned',
        self::ON_TRIP     => 'On trip',
        self::ON_LEAVE    => 'On leave',
        self::ABSENT      => 'Absent',
        self::SUSPENDED   => 'Suspended',
        self::UNAVAILABLE => 'Unavailable',
    ];

    public static function isValid(string $availability): bool
    {
        return in_array($availability, self::ALL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function label(string $availability): string
    {
        return self::LABELS[$availability] ?? $availability;
    }
}
