<?php

namespace App\Support\Numbering\Reset;

use App\Support\Numbering\NumberingContext;

/** Restarts each calendar year. */
final class YearlyResetStrategy implements ResetStrategyInterface
{
    public function key(): string
    {
        return 'yearly';
    }

    public function label(): string
    {
        return 'Every calendar year';
    }

    public function periodKey(NumberingContext $context): string
    {
        return 'Y'.$context->at->format('Y');
    }
}
