<?php

namespace App\Support\Numbering\Reset;

use App\Support\Numbering\NumberingContext;

/** One continuous sequence forever. */
final class NeverResetStrategy implements ResetStrategyInterface
{
    public function key(): string
    {
        return 'never';
    }

    public function label(): string
    {
        return 'Never';
    }

    public function periodKey(NumberingContext $context): string
    {
        return 'never';
    }
}
