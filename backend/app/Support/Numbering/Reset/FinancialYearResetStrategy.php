<?php

namespace App\Support\Numbering\Reset;

use App\Services\Settings\SettingsService;
use App\Support\Numbering\NumberingContext;

/**
 * Restarts each financial year. The FY boundary comes from the tenant's
 * `numbering.fy_start_month` setting (default 4 = April, the Indian FY), so this
 * strategy holds no configuration of its own.
 */
final class FinancialYearResetStrategy implements ResetStrategyInterface
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function key(): string
    {
        return 'financial_year';
    }

    public function label(): string
    {
        return 'Every financial year';
    }

    public function periodKey(NumberingContext $context): string
    {
        $startMonth = (int) ($this->settings->get($context->tenantId, 'numbering', 'fy_start_month') ?? 4);
        $startMonth = max(1, min(12, $startMonth));

        $year = (int) $context->at->format('Y');
        $startYear = (int) $context->at->format('n') >= $startMonth ? $year : $year - 1;

        return 'FY'.$startYear.'-'.substr((string) ($startYear + 1), -2);
    }
}
