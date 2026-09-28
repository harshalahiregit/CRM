<?php

namespace App\Support\Transport;

/**
 * Transport Order lifecycle (SNG-TRN-006).
 *
 * These four states are Step 11's Order state machine (SM-ORD, LOCKED), chosen
 * over the alternatives for a specific reason recorded in the scope agreement:
 * five different Order machines appear across the documents (Step 9 has 6 states,
 * Step 11 has 4, STOS-LSM 12, STOS-OPS 15, STOS-DB 12). Step 11 was ruled
 * definitive because it is the canonical technical registry AND because it is the
 * only one that contains APPROVED — the state SNG-TRN-007 depends on when it
 * "converts an approved order into a trip". Step 9's list has no APPROVED at all.
 *
 * Widening this set is a Class D domain change (Step 9 Change_Control) and needs
 * architecture approval, not a new constant.
 *
 * Transport-owned. Stored on transport_orders.order_status as a plain string.
 */
final class OrderStatus
{
    public const DRAFT     = 'draft';
    public const SUBMITTED = 'submitted';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';

    public const ALL = [self::DRAFT, self::SUBMITTED, self::APPROVED, self::REJECTED];

    /** SM-ORD: draft is the initial state (Step 11 State_Machines). */
    public const INITIAL = self::DRAFT;

    /** SM-ORD marks rejected terminal; approved is active, not terminal. */
    public const TERMINAL = [self::REJECTED];

    /**
     * Allowed transitions. Anything absent is refused — Step 11's rule is that
     * no state or transition changes without state-machine approval, so an
     * undeclared move is a bug, never an omission to be papered over.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::DRAFT     => [self::SUBMITTED],
        self::SUBMITTED => [self::APPROVED, self::REJECTED, self::DRAFT],
        self::APPROVED  => [],
        self::REJECTED  => [],
    ];

    public const LABELS = [
        self::DRAFT     => 'Draft',
        self::SUBMITTED => 'Submitted',
        self::APPROVED  => 'Approved',
        self::REJECTED  => 'Rejected',
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Only an approved order may become a trip (SNG-TRN-007). */
    public static function isTripEligible(string $status): bool
    {
        return $status === self::APPROVED;
    }
}
