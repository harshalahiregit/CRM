<?php

namespace App\Services\Accounts\Reports;

/**
 * Profit & Loss — income and expense ledger movements over a period.
 * Net = Σ(income) − Σ(expense). Opening balances are excluded: P&L is a
 * period statement, not a cumulative one.
 */
class ProfitAndLossReport extends ReportService
{
    public function generate(int $tenantId, array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to   = $filters['to'] ?? null;

        $balances = $this->ledgerBalances($tenantId, $from, $to, includeOpening: false);

        $income  = [];
        $expense = [];
        $totalIncome = 0.0;
        $totalExpense = 0.0;

        foreach ($balances as $b) {
            if ($b->nature === 'income') {
                $amount = -$b->signed; // credit-positive
                if (abs($amount) < 0.005) {
                    continue;
                }
                $income[] = ['ledger_id' => $b->id, 'ledger' => $b->name, 'group' => $b->group_name, 'amount' => round($amount, 2)];
                $totalIncome += $amount;
            } elseif ($b->nature === 'expense') {
                $amount = $b->signed; // debit-positive
                if (abs($amount) < 0.005) {
                    continue;
                }
                $expense[] = ['ledger_id' => $b->id, 'ledger' => $b->name, 'group' => $b->group_name, 'amount' => round($amount, 2)];
                $totalExpense += $amount;
            }
        }

        $net = round($totalIncome - $totalExpense, 2);

        return [
            'from' => $from,
            'to'   => $to,
            'income'  => $income,
            'expense' => $expense,
            'totals' => [
                'income'  => round($totalIncome, 2),
                'expense' => round($totalExpense, 2),
                'net_profit' => $net,
                'is_profit'  => $net >= 0,
            ],
        ];
    }
}
