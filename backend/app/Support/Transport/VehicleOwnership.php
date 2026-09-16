<?php

namespace App\Support\Transport;

/**
 * Vehicle ownership type — STOS-FLEET §10, verbatim:
 * "Owned / Financed / Leased / Contracted / Attached / Other".
 *
 * §10 explains why it is a first-class field rather than a note: ownership drives
 * "asset cost; EMI; profitability; utilization". None of those tickets exist yet
 * (SNG-TRN-012/018), so nothing reads this today — it is captured now because it
 * is a fact about the vehicle recorded at onboarding, and backfilling it across a
 * live fleet later is a data-entry project rather than a migration.
 *
 * Not in Step 11: no ownership enum exists in the canonical registry (defect D-4).
 */
final class VehicleOwnership
{
    public const OWNED      = 'owned';
    public const FINANCED   = 'financed';
    public const LEASED     = 'leased';
    public const CONTRACTED = 'contracted';
    public const ATTACHED   = 'attached';
    public const OTHER      = 'other';

    public const ALL = [
        self::OWNED, self::FINANCED, self::LEASED,
        self::CONTRACTED, self::ATTACHED, self::OTHER,
    ];

    public const DEFAULT = self::OWNED;

    public const LABELS = [
        self::OWNED      => 'Owned',
        self::FINANCED   => 'Financed',
        self::LEASED     => 'Leased',
        self::CONTRACTED => 'Contracted',
        self::ATTACHED   => 'Attached',
        self::OTHER      => 'Other',
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}
