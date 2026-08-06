<?php

namespace App\Support\Numbering\Reset;

use App\Support\Numbering\NumberingContext;

/**
 * A reset rule, expressed as a PERIOD KEY rather than a destructive action.
 *
 * The engine never "resets" a counter in place: it asks the strategy for the key
 * of the period the given moment falls in, and allocates from the sequence row for
 * that key. A new period therefore yields a brand-new row starting at the
 * configured starting_number, while every previously issued range stays intact and
 * auditable. Adding a rule = adding a strategy; the engine has no switch.
 */
interface ResetStrategyInterface
{
    /** Stored in document_number_configs.reset_rule. */
    public function key(): string;

    /** Human label for the settings UI. */
    public function label(): string;

    /** The period this moment belongs to, e.g. 'Y2026' or 'M2026-09'. */
    public function periodKey(NumberingContext $context): string;
}
