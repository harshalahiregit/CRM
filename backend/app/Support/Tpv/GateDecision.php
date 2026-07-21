<?php

namespace App\Support\Tpv;

/**
 * The outcome of presenting a badge at the site gate.
 *
 * Admit  — green:  clear to enter.
 * Warn   — amber:  may enter, but something needs attention (final strike,
 *                  lapsing medical). The guard sees it; entry is not blocked.
 * Deny   — red:    must not enter.
 *
 * Stored on tpv_gate_scans.decision as a plain string.
 */
final class GateDecision
{
    public const ADMIT = 'Admit';
    public const WARN  = 'Warn';
    public const DENY  = 'Deny';

    public const ALL = [self::ADMIT, self::WARN, self::DENY];

    public const LABELS = [
        self::ADMIT => 'Admit',
        self::WARN  => 'Admit with Warning',
        self::DENY  => 'Do Not Admit',
    ];

    /** Decisions that permit entry (a warning still lets the worker in). */
    public const PERMITS_ENTRY = [self::ADMIT, self::WARN];

    public static function label(?string $d): string
    {
        return self::LABELS[$d] ?? (string) $d;
    }

    public static function permitsEntry(?string $d): bool
    {
        return in_array($d, self::PERMITS_ENTRY, true);
    }
}
