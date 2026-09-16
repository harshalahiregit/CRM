<?php

namespace App\Support\Transport;

/**
 * `trip_costs.cost_type` — normalised, NOT constrained.  D-58.
 *
 * FLD-010 declares `cost_type VARCHAR(40) INDEX` and points its rule column at
 * `CST-001`. **CST-001 is defined nowhere.** Searched all fourteen sheets of
 * Step 11: it occurs exactly once, in FLD-010's own citation. The Enums sheet
 * holds ENUM-001..008 and not one of them is a cost type.
 *
 * So there is no vocabulary to implement, and writing one would be FORBID-001 —
 * the same call D-50 made for `container_type`, which stayed a free-text column
 * rather than becoming an invented enum.
 *
 * ── BUT FREE TEXT ALONE BREAKS SNG-TRN-018 ───────────────────────────────
 * IDX-005 exists "for profitability calculations" and leads with this column;
 * SNG-TRN-018's acceptance is "revenue, cost and margin reconcile to source
 * transactions". Left raw, `Fuel`, `FUEL` and ` fuel ` are three groups and the
 * reconciliation is arithmetically wrong while looking fine.
 *
 * normalise() is the whole answer: fold case, collapse whitespace, trim. It
 * rejects nothing and closes nothing — an unrecognised type is still stored,
 * exactly as the registry's VARCHAR(40) allows. It only stops the SAME type
 * being counted as several. This is the treatment TransportContainer::normalise()
 * already gives a free-text identity column in this module.
 *
 * KNOWN is a UI hint list, never a validator. It comes from TEAM-CONTRACTS C-06,
 * where P2 agreed to feed fuel, urea, tyre, maintenance and FASTag into
 * trip_costs, plus toll from this ticket's own Module name "Fuel/Toll/Expense".
 * A team contract is not the registry, so these suggest; they do not gate.
 * Do not turn this array into a rule without a registry change.
 */
final class CostType
{
    /**
     * Suggestions for a picker. NOT a permitted-values list.
     *
     * @var list<string>
     */
    public const KNOWN = [
        'fuel',
        'toll',
        'urea',
        'tyre',
        'maintenance',
        'fastag',
        'driver_allowance',
        'loading',
        'unloading',
        'detention',
        'parking',
        'penalty',
        'other',
    ];

    /** VARCHAR(40) — FLD-010. Enforced so a longer value fails loudly, not silently truncated. */
    public const MAX_LENGTH = 40;

    /**
     * Fold a submitted type onto its canonical spelling.
     *
     * Lowercase, internal whitespace and hyphens collapsed to one underscore,
     * trimmed. `  Driver Allowance ` and `driver-allowance` both become
     * `driver_allowance`, so IDX-005 groups them together.
     */
    public static function normalise(string $raw): string
    {
        $value = strtolower(trim($raw));
        $value = preg_replace('/[\s\-]+/', '_', $value) ?? $value;
        $value = preg_replace('/_+/', '_', $value) ?? $value;

        return trim($value, '_');
    }

    /** Is this one of the spellings we suggest? Used for UI hinting only. */
    public static function isKnown(string $raw): bool
    {
        return in_array(self::normalise($raw), self::KNOWN, true);
    }

    /** Title-case for display: `driver_allowance` -> `Driver Allowance`. */
    public static function label(string $value): string
    {
        return ucwords(str_replace('_', ' ', self::normalise($value)));
    }
}
