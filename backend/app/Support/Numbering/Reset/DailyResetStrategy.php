<?php

namespace App\Support\Numbering\Reset;

use App\Support\Numbering\NumberingContext;

/** Restarts each day. */
final class DailyResetStrategy implements ResetStrategyInterface
{
    public function key(): string
    {
        return 'daily';
    }

    public function label(): string
    {
        return 'Every day';
    }

    public function periodKey(NumberingContext $context): string
    {
        return 'D'.$context->at->format('Y-m-d');
    }
}
