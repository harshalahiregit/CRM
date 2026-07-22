<?php

namespace App\Services\Accounts;

use App\Models\Accounts\Bill;
use App\Models\Accounts\Voucher;
use App\Services\Accounts\Reports\AgeingReport;
use App\Services\Accounts\Reports\ProfitAndLossReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounts landing-page summary (old-CRM Accounting dashboard parity). Every
 * figure here is derived at query time from posted vouchers or the existing
 * report services — nothing is a second source of truth for money.
 */
class DashboardService
{
    public function __construct(
        private BankAccountService $bankAccounts,
        private ProfitAndLossReport $profitAndLoss,
        private AgeingReport $ageing,
    ) {
    }

    public function summary(int $tenantId): array
    {
        $today = now();
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();

        $banks = $this->bankAccounts->list($tenantId);
        $cashAndBank = round(array_sum(array_column($banks, 'current_balance')), 2);

        $pnl = $this->profitAndLoss->generate($tenantId, ['from' => $monthStart, 'to' => $monthEnd]);

        $receivable = $this->ageing->generate($tenantId, 'receivable', ['to' => $today->toDateString()]);
        $payableBills = round((float) Bill::forTenant($tenantId)->where('status', 'unpaid')->sum('amount'), 2);
        $overdueBills = round((float) Bill::forTenant($tenantId)->where('status', 'unpaid')
            ->where('due_date', '<', $today->toDateString())->sum('amount'), 2);

        // Last 6 months income vs expense — a plain data series, chart rendered client-side.
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $today->copy()->subMonths($i);
            $row = $this->profitAndLoss->generate($tenantId, [
                'from' => $m->copy()->startOfMonth()->toDateString(),
                'to'   => $m->copy()->endOfMonth()->toDateString(),
            ]);
            $trend[] = [
                'month'   => $m->format('M'),
                'income'  => (float) ($row['totals']['income'] ?? 0),
                'expense' => (float) ($row['totals']['expense'] ?? 0),
            ];
        }

        // Top expense categories this month (for a breakdown, no chart lib needed client-side).
        $expenseByLedger = DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->join('acc_ledgers as l', 'l.id', '=', 'vl.ledger_id')
            ->join('acc_account_groups as g', 'g.id', '=', 'l.group_id')
            ->where('vl.tenant_id', $tenantId)->where('v.status', 'posted')->whereNull('v.deleted_at')
            ->whereBetween('v.date', [$monthStart, $monthEnd])
            ->where('g.nature', 'expense')
            ->groupBy('l.id', 'l.name')
            ->selectRaw('l.name, COALESCE(SUM(vl.debit),0) - COALESCE(SUM(vl.credit),0) as amount')
            ->havingRaw('COALESCE(SUM(vl.debit),0) - COALESCE(SUM(vl.credit),0) > 0')
            ->orderByDesc('amount')
            ->limit(6)
            ->get();

        $recentVouchers = Voucher::forTenant($tenantId)->where('status', 'posted')
            ->with('voucherType:id,code,name')
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'voucher_type_id', 'number', 'date', 'narration', 'total_amount']);

        return [
            'cash_and_bank'   => $cashAndBank,
            'bank_accounts'   => $banks,
            'month_income'    => (float) ($pnl['totals']['income'] ?? 0),
            'month_expense'   => (float) ($pnl['totals']['expense'] ?? 0),
            'month_net'       => (float) ($pnl['totals']['net_profit'] ?? 0),
            'receivable_total' => (float) ($receivable['totals']['total'] ?? 0),
            'payable_total'    => $payableBills,
            'payable_overdue'  => $overdueBills,
            'trend'            => $trend,
            'expense_breakdown' => $expenseByLedger,
            'recent_vouchers'   => $recentVouchers,
        ];
    }
}
