<?php

namespace App\Services\Accounts\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared read-only query helpers for all financial reports. Everything is DERIVED
 * from posted acc_voucher_lines — no cached aggregate that can drift (spec §5.6).
 *
 * Convention: balances are computed "debit-positive" (a positive signed value is
 * a net debit). Report classes flip the sign per account nature for presentation.
 */
abstract class ReportService
{
    /**
     * Per-ledger balances for a tenant, optionally within [$from, $to] and
     * optionally including the ledger's opening balance.
     *
     * @return Collection<int,object> each: id, name, code, group_id, group_name,
     *   nature, opening_signed, debit_sum, credit_sum, signed (debit-positive)
     */
    protected function ledgerBalances(int $tenantId, ?string $from, ?string $to, bool $includeOpening): Collection
    {
        // Movements per ledger from posted vouchers in the date window.
        $movements = DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->where('vl.tenant_id', $tenantId)
            ->where('v.status', 'posted')
            ->whereNull('v.deleted_at')
            ->when($from, fn ($q) => $q->whereDate('v.date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('v.date', '<=', $to))
            ->groupBy('vl.ledger_id')
            ->select('vl.ledger_id', DB::raw('SUM(vl.debit) as debit_sum'), DB::raw('SUM(vl.credit) as credit_sum'));

        $rows = DB::table('acc_ledgers as l')
            ->join('acc_account_groups as g', 'g.id', '=', 'l.group_id')
            ->leftJoinSub($movements, 'm', 'm.ledger_id', '=', 'l.id')
            ->where('l.tenant_id', $tenantId)
            ->whereNull('l.deleted_at')
            ->select(
                'l.id', 'l.name', 'l.code', 'l.opening_balance', 'l.opening_balance_type',
                'g.id as group_id', 'g.name as group_name', 'g.nature',
                DB::raw('COALESCE(m.debit_sum, 0) as debit_sum'),
                DB::raw('COALESCE(m.credit_sum, 0) as credit_sum'),
            )
            ->orderBy('g.name')
            ->orderBy('l.name')
            ->get();

        return $rows->map(function ($r) use ($includeOpening) {
            $openingSigned = $includeOpening
                ? (float) $r->opening_balance * ($r->opening_balance_type === 'dr' ? 1 : -1)
                : 0.0;

            $movementSigned = (float) $r->debit_sum - (float) $r->credit_sum;

            $r->opening_signed = round($openingSigned, 2);
            $r->debit_sum      = round((float) $r->debit_sum, 2);
            $r->credit_sum     = round((float) $r->credit_sum, 2);
            $r->signed         = round($openingSigned + $movementSigned, 2); // debit-positive closing

            return $r;
        });
    }

    /**
     * Net profit (income − expense) over a period — used by the Balance Sheet to
     * carry current-year earnings into equity. Income is credit-positive,
     * expense debit-positive; profit = income − expense.
     */
    protected function netProfit(int $tenantId, ?string $from, ?string $to): float
    {
        $balances = $this->ledgerBalances($tenantId, $from, $to, includeOpening: false);

        return $this->netProfitFromBalances($balances);
    }

    /**
     * Net profit from an already-fetched ledgerBalances() collection — uses period
     * MOVEMENT (debit_sum − credit_sum), so it works whether or not opening was
     * included. Lets callers avoid a second full ledger scan.
     */
    protected function netProfitFromBalances(Collection $balances): float
    {
        $income = $balances->where('nature', 'income')->sum(fn ($r) => (float) $r->credit_sum - (float) $r->debit_sum);
        $expense = $balances->where('nature', 'expense')->sum(fn ($r) => (float) $r->debit_sum - (float) $r->credit_sum);

        return round($income - $expense, 2);
    }
}
