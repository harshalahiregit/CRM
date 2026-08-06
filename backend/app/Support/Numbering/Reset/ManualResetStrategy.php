<?php

namespace App\Support\Numbering\Reset;

use App\Support\Numbering\NumberingContext;

/**
 * Never rolls over on its own — the sequence continues until an administrator
 * explicitly resets it. (The engine appends the config's epoch to every period
 * key, so the explicit reset is what opens the next period.)
 */
final class ManualResetStrategy implements ResetStrategyInterface
{
    public function key(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Manual only';
    }

    public function periodKey(NumberingContext $context): string
    {
        return 'manual';
    }
}
