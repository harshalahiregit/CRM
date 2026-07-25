<?php

namespace App\Support\Purchase;

/**
 * The kind of purchase contract.
 *
 * RATE_CONTRACT — a locked rate card (per-item pre-negotiated prices) POs draw on.
 * MSA — a master service agreement: terms + an attached document; line items are
 *       optional (it may have no rate card at all).
 */
final class PurchaseContractType
{
    public const RATE_CONTRACT = 'rate_contract';
    public const MSA           = 'msa';

    public const ALL = [self::RATE_CONTRACT, self::MSA];

    public const LABELS = [
        self::RATE_CONTRACT => 'Rate Contract',
        self::MSA           => 'Master Service Agreement',
    ];

    public static function label(?string $t): string
    {
        return self::LABELS[$t] ?? (string) $t;
    }

    public static function isValid(?string $t): bool
    {
        return in_array($t, self::ALL, true);
    }

    /** A rate contract must carry at least one locked line to be activated. */
    public static function requiresRateLines(?string $t): bool
    {
        return $t === self::RATE_CONTRACT;
    }
}
