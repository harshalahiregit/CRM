<?php

namespace App\Services\Accounts\Reports;

/**
 * Balance Sheet as of a date — asset, liability and equity ledger balances
 * (opening + all movement up to the date), plus current-period net profit carried
 * into equity. Invariant: Assets = Liabilities + Equity + Net Profit.
 */
class BalanceSheetReport extends ReportService
{
    public function generate(int $tenantId, array $filters): array
    {
        $asOf = $filters['to'] ?? null;

        $balances = $this->ledgerBalances($tenantId, from: null, to: $asOf, includeOpening: true);

        $assets = [];
        $liabilities = [];
        $equity = [];
        $totalAssets = 0.0;
        $totalLiabilities = 0.0;
        $totalEquity = 0.0;

        foreach ($balances as $b) {
            if ($b->nature === 'asset') {
                $amount = $b->signed; // debit-positive
                if (abs($amount) < 0.005) {
                    continue;
                }
                $assets[] = $this->row($b, $amount);
                $totalAssets += $amount;
            } elseif ($b->nature === 'liability') {
                $amount = -$b->signed; // credit-positive
                if (abs($amount) < 0.005) {
                    continue;
                }
                $liabilities[] = $this->row($b, $amount);
                $totalLiabilities += $amount;
            } elseif ($b->nature === 'equity') {
                $amount = -$b->signed; // credit-positive
                if (abs($amount) < 0.005) {
                    continue;
                }
                $equity[] = $this->row($b, $amount);
                $totalEquity += $amount;
            }
        }

        // Current-period earnings (income − expense up to the as-of date) sit on
        // the liabilities/equity side as "Current Year Profit/Loss". Derived from the
        // balances already fetched above — no second ledger scan.
        $netProfit = $this->netProfitFromBalances($balances);

        $liabilitiesAndEquity = round($totalLiabilities + $totalEquity + $netProfit, 2);

        return [
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'net_profit' => $netProfit,
            'totals' => [
                'assets' => round($totalAssets, 2),
                'liabilities' => round($totalLiabilities, 2),
                'equity' => round($totalEquity, 2),
                'liabilities_and_equity' => $liabilitiesAndEquity,
                'balanced' => abs($totalAssets - $liabilitiesAndEquity) < 0.005,
            ],
        ];
    }

    private function row(object $b, float $amount): array
    {
        return [
            'ledger_id' => $b->id,
            'ledger'    => $b->name,
            'group'     => $b->group_name,
            'amount'    => round($amount, 2),
        ];
    }
}
