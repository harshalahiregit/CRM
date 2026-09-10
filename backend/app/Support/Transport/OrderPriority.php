<?php

namespace App\Support\Transport;

/**
 * Transport Order priority.
 *
 * Exactly four values, specified twice and identically: STOS-OPS §9 ("Each order
 * must have: Normal, Priority, Urgent, Critical") and BRW-018 Priority
 * Classification. Priority is mandatory, not optional.
 *
 * OPS §9 states priority affects allocation, SLA, escalation, notification and
 * management visibility — all of which belong to later tickets. This ticket
 * captures and stores the classification only; nothing here acts on it.
 *
 * Transport-owned. Stored on transport_orders.priority as a plain string.
 */
final class OrderPriority
{
    public const NORMAL   = 'Normal';
    public const PRIORITY = 'Priority';
    public const URGENT   = 'Urgent';
    public const CRITICAL = 'Critical';

    public const ALL = [self::NORMAL, self::PRIORITY, self::URGENT, self::CRITICAL];

    public const DEFAULT = self::NORMAL;

    /** Ascending severity, for ordering a queue by how much it matters. */
    public const RANK = [
        self::NORMAL   => 0,
        self::PRIORITY => 1,
        self::URGENT   => 2,
        self::CRITICAL => 3,
    ];

    public static function isValid(string $priority): bool
    {
        return in_array($priority, self::ALL, true);
    }

    public static function rank(string $priority): int
    {
        return self::RANK[$priority] ?? 0;
    }
}
