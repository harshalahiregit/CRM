<?php

namespace App\Services\Accounts\Reports;

/**
 * Trial Balance — every ledger's closing balance (opening + movement up to the
 * as-of date) split into a debit or credit column. Total debits must equal total
 * credits; if they don't, the books are broken (they can't be, given the posting
 * invariant + opening balances that net to zero).
 */
class TrialBalanceReport extends ReportService
{
    public function generate(int $tenantId, array $filters): array
    {
        $asOf = $filters['to'] ?? null;

        $balances = $this->ledgerBalances($tenantId, from: null, to: $asOf, includeOpening: true);

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($balances as $b) {
            if (abs($b->signed) < 0.005) {
                continue; // zero-balance ledgers are omitted
            }

            $debit  = $b->signed > 0 ? $b->signed : 0.0;
            $credit = $b->signed < 0 ? -$b->signed : 0.0;
            $totalDebit  += $debit;
            $totalCredit += $credit;

            $rows[] = [
                'ledger_id'  => $b->id,
                'ledger'     => $b->name,
                'group'      => $b->group_name,
                'nature'     => $b->nature,
                'debit'      => round($debit, 2),
                'credit'     => round($credit, 2),
            ];
        }

        return [
            'as_of' => $asOf,
            'rows'  => $rows,
            'totals' => [
                'debit'      => round($totalDebit, 2),
                'credit'     => round($totalCredit, 2),
                'balanced'   => abs($totalDebit - $totalCredit) < 0.005,
            ],
        ];
    }
}
