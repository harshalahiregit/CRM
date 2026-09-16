<?php

namespace App\Support\Transport;

/**
 * `trip_costs.source` — where a cost fact came from.  SNG-TRN-012.
 *
 * ── WHY THIS ONE *IS* A CLOSED LIST, WHEN CostType IS NOT ────────────────
 * The distinction matters and it is deliberate.
 *
 * `cost_type` has a registry field (FLD-010) whose vocabulary reference
 * (CST-001) is missing. Something was meant to define it and does not, so
 * supplying a list would be substituting a developer's judgement for a product
 * decision — FORBID-001. It stays open. See CostType.
 *
 * `source` has NO registry field at all. It exists because SNG-TRN-012's
 * acceptance criterion is "Every cost is linked to trip and SOURCE" and
 * SNG-TRN-018's is "reconcile to SOURCE TRANSACTIONS". The field is constructed
 * from those two sentences, and defining what it may hold is part of
 * constructing it — there is no registry vocabulary being overridden. It is
 * also not business data: it records which mechanism produced the row, and an
 * open-ended origin marker cannot be reconciled against anything.
 *
 * Kept deliberately small. A new producer is a code change and a review, which
 * is the correct weight for "a new thing may now write to the cost ledger".
 *
 * ── THE DUPLICATE RULE RIDES ON THIS ─────────────────────────────────────
 * The unique index is (tenant_id, source, source_ref, cost_type). MANUAL rows
 * carry a NULL source_ref and are exempt, because a driver can genuinely buy
 * fuel twice in a day. Every other source is expected to supply the identifier
 * of the transaction it is mirroring, and that is what makes a re-run or a
 * duplicate callback harmless — a listed edge case for this ticket.
 */
final class CostSource
{
    /** Typed in by a person. source_ref is NULL; look-alikes are confirmed, not blocked. */
    public const MANUAL = 'manual';

    /**
     * Raised from an approved DB-008 `trip_expenses` row.
     *
     * Declared, not yet reachable: the cost/expense boundary is un-ruled
     * (D-58, ARCHITECTURE_REVIEW_REQUIRED) and `trip_expenses` is not built.
     * Named here so the reconciliation path has somewhere to land, and so
     * nobody invents a second spelling for it later.
     */
    public const EXPENSE = 'expense';

    /**
     * Fed by P2's fleet telemetry — TEAM-CONTRACTS C-06, fuel/urea/tyre/
     * maintenance/FASTag. Not started on their side; declared for the same
     * reason as EXPENSE.
     */
    public const TELEMETRY = 'telemetry';

    /** Raised by SNG-TRN-017 settlement when a balance resolves into a cost. */
    public const SETTLEMENT = 'settlement';

    /** Bulk load of historical costs. Carries the row identity as source_ref. */
    public const IMPORT = 'import';

    /** @var list<string> */
    public const ALL = [
        self::MANUAL,
        self::EXPENSE,
        self::TELEMETRY,
        self::SETTLEMENT,
        self::IMPORT,
    ];

    /**
     * Sources that must name the transaction they mirror.
     *
     * Everything except MANUAL. Without a source_ref the unique index cannot
     * stop a re-delivered webhook or a re-run import from counting the same
     * money twice, and a double-counted cost is a wrong margin in SNG-TRN-018.
     *
     * @var list<string>
     */
    public const REQUIRES_REF = [
        self::EXPENSE,
        self::TELEMETRY,
        self::SETTLEMENT,
        self::IMPORT,
    ];

    /** Sources a human may write directly through the API. */
    public const OPERATOR_WRITABLE = [
        self::MANUAL,
        self::IMPORT,
    ];

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }

    public static function requiresRef(string $source): bool
    {
        return in_array($source, self::REQUIRES_REF, true);
    }

    public static function label(string $source): string
    {
        return match ($source) {
            self::MANUAL     => 'Entered manually',
            self::EXPENSE    => 'From an approved expense',
            self::TELEMETRY  => 'From fleet telemetry',
            self::SETTLEMENT => 'From settlement',
            self::IMPORT     => 'Imported',
            default          => $source,
        };
    }
}
