<?php

namespace App\Support\Numbering\Reset;

use App\Support\Numbering\NumberingContext;

/** Restarts each calendar month. */
final class MonthlyResetStrategy implements ResetStrategyInterface
{
    public function key(): string
    {
        return 'monthly';
    }

    public function label(): string
    {
        return 'Every month';
    }

    public function periodKey(NumberingContext $context): string
    {
        return 'M'.$context->at->format('Y-m');
    }
}
