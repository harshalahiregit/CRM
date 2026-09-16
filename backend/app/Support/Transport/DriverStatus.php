<?php

namespace App\Support\Transport;

/**
 * Driver lifecycle status — "does this person drive for us at all?"
 *
 * Deliberately a SECOND field alongside DriverAvailability, ruled by the owner on
 * 2026-09-07. The package describes two axes and never says they share a column:
 *   Step 2 BO-009  Active / Inactive / Blocked          <- this class
 *   STOS-LSM §38   a lifecycle ending ACTIVE → INACTIVE <- this class
 *   STOS-DB §44    available / on_trip / on_leave / ...  <- DriverAvailability
 *
 * They answer different questions, and collapsing them loses information that
 * matters operationally. "Left the company" and "off sick today" are both
 * "not usable now", but only one of them ends. Merged into a single column, a
 * driver returning from suspension has nowhere to record that they are employed
 * but not yet cleared, and reinstating anyone means guessing what they were
 * before. transport_vehicles merges its axes because an asset that is SOLD is
 * simply gone; a person can be inactive in March and active again in April.
 *
 * ── WHY LSM §38's EARLIER STATES ARE NOT HERE ─────────────────────────────
 * LSM §38's full lifecycle opens with RECRUITMENT_REQUIRED → CANDIDATE →
 * DOCUMENT_VERIFICATION → ELIGIBILITY_CHECK → TRAINING → ACTIVE. Those are
 * pre-employment states, and STOS-INT §77 and STOS-DB §118 both place
 * recruitment in Sangoe Recruitment, not Transport ("Recruitment belongs to
 * Sangoe Recruitment. STOS may create driver manpower requirements."). Declaring
 * them here would assert Transport owns a hiring pipeline it does not, so this
 * enum starts where Transport's responsibility starts: at an onboarded driver.
 *
 * BLOCKED is retained from BO-009 and is distinct from SUSPENDED availability:
 * blocked is an administrative bar on the person (BRW-081's management review
 * outcome), suspended is a temporary operational state.
 */
final class DriverStatus
{
    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';
    public const BLOCKED  = 'blocked';

    public const ALL = [self::ACTIVE, self::INACTIVE, self::BLOCKED];

    public const INITIAL = self::ACTIVE;

    /** Only an active driver is a candidate for anything. */
    public const ALLOCATABLE = [self::ACTIVE];

    public const TRANSITIONS = [
        self::ACTIVE   => [self::INACTIVE, self::BLOCKED],
        self::INACTIVE => [self::ACTIVE],
        self::BLOCKED  => [self::ACTIVE, self::INACTIVE],
    ];

    public const LABELS = [
        self::ACTIVE   => 'Active',
        self::INACTIVE => 'Inactive',
        self::BLOCKED  => 'Blocked',
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
