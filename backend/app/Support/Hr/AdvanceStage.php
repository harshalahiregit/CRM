<?php

namespace App\Support\Hr;

/**
 * The advance ladder: manager, then accounts, then director — each in turn.
 *
 * SangoeTrack has these three stages, but the CRM's screen against it approves
 * "without choosing a stage", so in practice any approver could complete a
 * request from any tier. Here the tier whose turn it is, is the ONLY tier that
 * can act, and the order is defined once — in LADDER — rather than reconstructed
 * from a chain of status comparisons scattered across a service.
 *
 * Every stage maps to the status a request reaches once that tier has approved.
 * The last one lands on APPROVED rather than 'director_approved', because what
 * matters after the final tier is that the money may now go out, and the thread
 * records who approved at each step anyway.
 */
class AdvanceStage
{
    /* ── the ladder, in order ────────────────────────────────────────── */

    public const MANAGER  = 'manager';
    public const ACCOUNTS = 'accounts';
    public const DIRECTOR = 'director';

    /** Order matters: this array IS the policy. */
    public const LADDER = [self::MANAGER, self::ACCOUNTS, self::DIRECTOR];

    /* ── statuses ────────────────────────────────────────────────────── */

    public const PENDING           = 'pending';
    public const MANAGER_APPROVED  = 'manager_approved';
    public const ACCOUNTS_APPROVED = 'accounts_approved';
    public const APPROVED          = 'approved';            // ready to disburse
    public const DISBURSED         = 'disbursed';
    public const SETTLEMENT_SUBMITTED = 'settlement_submitted';
    public const SETTLED           = 'settled';
    public const ON_HOLD           = 'on_hold';
    public const DECLINED          = 'declined';
    public const CANCELLED         = 'cancelled';

    public const ALL = [
        self::PENDING, self::MANAGER_APPROVED, self::ACCOUNTS_APPROVED, self::APPROVED,
        self::DISBURSED, self::SETTLEMENT_SUBMITTED, self::SETTLED,
        self::ON_HOLD, self::DECLINED, self::CANCELLED,
    ];

    /** What each tier's approval leaves the request at. */
    public const REACHES = [
        self::MANAGER  => self::MANAGER_APPROVED,
        self::ACCOUNTS => self::ACCOUNTS_APPROVED,
        self::DIRECTOR => self::APPROVED,
    ];

    /** The status a request must be at for a given tier to be next. */
    public const AWAITS = [
        self::MANAGER  => self::PENDING,
        self::ACCOUNTS => self::MANAGER_APPROVED,
        self::DIRECTOR => self::ACCOUNTS_APPROVED,
    ];

    /**
     * Whose turn it is, or null when nobody's — because the ladder is finished,
     * or the request is held, declined or already out of the door.
     */
    public static function nextTier(string $status): ?string
    {
        foreach (self::LADDER as $tier) {
            if (self::AWAITS[$tier] === $status) {
                return $tier;
            }
        }

        return null;
    }

    /** Still climbing: somebody's approval is the next thing that has to happen. */
    public static function awaitingApproval(string $status): bool
    {
        return self::nextTier($status) !== null;
    }

    /**
     * Open — the request is still live and can be held, declined or acted on.
     *
     * A disbursed advance is open too: it has not finished until it is settled,
     * which is the part track's own flow treats as an afterthought.
     */
    public static function isOpen(string $status): bool
    {
        return ! in_array($status, [self::SETTLED, self::DECLINED, self::CANCELLED], true);
    }

    public static function isDecided(string $status): bool
    {
        return ! self::isOpen($status);
    }

    /** Human wording, in one place, so the app and the CRM say the same thing. */
    public static function label(string $status): string
    {
        return [
            self::PENDING              => 'Awaiting manager approval',
            self::MANAGER_APPROVED     => 'Awaiting accounts approval',
            self::ACCOUNTS_APPROVED    => 'Awaiting director approval',
            self::APPROVED             => 'Ready to disburse',
            self::DISBURSED            => 'Disbursed — awaiting settlement',
            self::SETTLEMENT_SUBMITTED => 'Settlement under review',
            self::SETTLED              => 'Settled',
            self::ON_HOLD              => 'On hold',
            self::DECLINED             => 'Declined',
            self::CANCELLED            => 'Cancelled',
        ][$status] ?? $status;
    }

    public static function rule(): string
    {
        return 'in:' . implode(',', self::ALL);
    }

    public static function tierRule(): string
    {
        return 'in:' . implode(',', self::LADDER);
    }
}
