<?php

namespace App\Support\Tpv;

/**
 * Single source of truth for a TPV worker's lifecycle.
 *
 * Draft (registering) → Active (badge issued, site access) → Suspended /
 * Terminated. Termination is also what the punch/strike system will set once
 * that slice lands.
 *
 * Stored on tpv_workers.status as a plain string.
 */
final class TpvWorkerStatus
{
    public const DRAFT      = 'Draft';
    public const ACTIVE     = 'Active';
    public const SUSPENDED  = 'Suspended';
    public const TERMINATED = 'Terminated';

    public const ALL = [
        self::DRAFT, self::ACTIVE, self::SUSPENDED, self::TERMINATED,
    ];

    /** Statuses where registration details may still be edited. */
    public const EDITABLE = [
        self::DRAFT,
    ];

    /** Statuses that permit site entry (the gate scan will honour this). */
    public const ADMITTABLE = [
        self::ACTIVE,
    ];

    public const LABELS = [
        self::DRAFT      => 'Draft',
        self::ACTIVE     => 'Active',
        self::SUSPENDED  => 'Suspended',
        self::TERMINATED => 'Terminated',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function isEditable(?string $status): bool
    {
        return in_array($status, self::EDITABLE, true);
    }

    public static function isAdmittable(?string $status): bool
    {
        return in_array($status, self::ADMITTABLE, true);
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
