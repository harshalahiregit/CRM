<?php

namespace App\Services\Accounts;

use App\Models\Accounts\Bill;
use App\Models\Accounts\Cheque;
use App\Models\Accounts\CompanyProfile;
use App\Models\Accounts\Voucher;
use App\Services\Accounts\Reports\AgeingReport;
use App\Services\Accounts\Reports\CashFlowReport;
use App\Services\Accounts\Reports\ProfitAndLossReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounts landing-page summary (old-CRM Accounting dashboard parity). Every
 * figure here is derived at query time from posted vouchers or the existing
 * report services — nothing is a second source of truth for money.
 *
 * Now accepts an optional FY string (e.g. "2025-2026") to scope the trend
 * chart and other date-dependent views to the selected financial year.
 */
class DashboardService
{
    public function __construct(
        private BankAccountService $bankAccounts,
        private ProfitAndLossReport $profitAndLoss,
        private AgeingReport $ageing,
        private CashFlowReport $cashFlow,
    ) {
    }

    public function summary(int $tenantId, ?string $fy = null): array
    {
        $today = now();
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();

        // Resolve the financial year dates
        [$fyStart, $fyEnd] = $this->resolveFY($fy);

        // Base currency from company profile
        $baseCurrency = CompanyProfile::where('tenant_id', $tenantId)->value('base_currency') ?? 'INR';

        $banks = $this->bankAccounts->list($tenantId);
        $cashAndBank = round(array_sum(array_column($banks, 'current_balance')), 2);

        $pnl = $this->profitAndLoss->generate($tenantId, ['from' => $monthStart, 'to' => $monthEnd]);

        $cashFlow = $this->cashFlow->generate($tenantId, ['from' => $monthStart, 'to' => $monthEnd]);
        $receivable = $this->ageing->generate($tenantId, 'receivable', ['to' => $today->toDateString()]);
        $payableBills = round((float) Bill::forTenant($tenantId)->where('status', 'unpaid')->sum('amount'), 2);
        $overdueBills = round((float) Bill::forTenant($tenantId)->where('status', 'unpaid')
            ->where('due_date', '<', $today->toDateString())->sum('amount'), 2);

        // Last 6 months income vs expense — scoped to the FY if supplied.
        $trend = $this->buildTrend($tenantId, $fyStart, $fyEnd, $today);

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

        // ── Cheques due (post-dated cheques maturing in next 7 days) ────────
        $chequesDue = Cheque::forTenant($tenantId)
            ->whereIn('status', ['issued', 'post_dated'])
            ->where(function ($q) use ($today) {
                $q->where('pdc_due_date', '<=', $today->copy()->addDays(7)->toDateString())
                  ->orWhere(function ($q2) use ($today) {
                      $q2->where('is_pdc', false)
                         ->where('cheque_date', '<=', $today->copy()->addDays(7)->toDateString())
                         ->where('cheque_date', '>=', $today->toDateString());
                  });
            })
            ->count();

        // ── Convert status — unposted (pending auto-posting) source docs ─────
        $pendingInvoices = (int) DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('acc_vouchers')
                    ->whereColumn('acc_vouchers.source_id', 'sales_invoices.id')
                    ->where('acc_vouchers.source_type', 'invoice')
                    ->where('acc_vouchers.status', 'posted');
            })
            ->count();

        $pendingPayments = (int) DB::table('sales_payments')
            ->where('tenant_id', $tenantId)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('acc_vouchers')
                    ->whereColumn('acc_vouchers.source_id', 'sales_payments.id')
                    ->where('acc_vouchers.source_type', 'payment')
                    ->where('acc_vouchers.status', 'posted');
            })
            ->count();

        return [
            'base_currency'    => $baseCurrency,
            'fy_start'         => $fyStart,
            'fy_end'           => $fyEnd,
            'cash_and_bank'    => $cashAndBank,
            'bank_accounts'    => $banks,
            'month_income'     => (float) ($pnl['totals']['income'] ?? 0),
            'month_expense'    => (float) ($pnl['totals']['expense'] ?? 0),
            'month_net'        => (float) ($pnl['totals']['net_profit'] ?? 0),
            'receivable_total' => (float) ($receivable['totals']['total'] ?? 0),
            'payable_total'    => $payableBills,
            'payable_overdue'  => $overdueBills,
            'cash_flow'        => $cashFlow,
            'trend'            => $trend,
            'expense_breakdown'=> $expenseByLedger,
            'recent_vouchers'  => $recentVouchers,
            'cheques_due'      => $chequesDue,
            'convert_status'   => [
                'pending_invoices' => $pendingInvoices,
                'pending_payments' => $pendingPayments,
                'total'            => $pendingInvoices + $pendingPayments,
            ],
        ];
    }

    /**
     * Resolve FY string "2025-2026" → [start_date, end_date].
     * India FY runs Apr 1 → Mar 31. Falls back to current FY.
     */
    private function resolveFY(?string $fy): array
    {
        $today = now();
        $currentFYStart = $today->month >= 4 ? $today->year : $today->year - 1;

        if ($fy && preg_match('/^(\d{4})-(\d{4})$/', $fy, $m)) {
            $startYear = (int) $m[1];
        } else {
            $startYear = $currentFYStart;
        }

        return [
            "{$startYear}-04-01",
            ($startYear + 1) . "-03-31",
        ];
    }

    /**
     * Build the 6-month or 12-month income vs expense trend,
     * scoped to the selected financial year.
     */
    private function buildTrend(int $tenantId, string $fyStart, string $fyEnd, Carbon $today): array
    {
        $trend = [];
        // Show last 6 months or from FY start, whichever is more recent
        $start = Carbon::parse($fyStart);
        $end = min(Carbon::parse($fyEnd), $today->copy()->endOfMonth());

        // Walk month by month from (end - 5 months) to end
        $cursor = $end->copy()->startOfMonth()->subMonths(5);
        if ($cursor->lt($start)) {
            $cursor = $start->copy()->startOfMonth();
        }

        while ($cursor->lte($end)) {
            $mStart = $cursor->copy()->startOfMonth()->toDateString();
            $mEnd = $cursor->copy()->endOfMonth()->toDateString();

            $row = $this->profitAndLoss->generate($tenantId, [
                'from' => $mStart,
                'to'   => $mEnd,
            ]);

            $trend[] = [
                'month'   => $cursor->format('M'),
                'income'  => (float) ($row['totals']['income'] ?? 0),
                'expense' => (float) ($row['totals']['expense'] ?? 0),
            ];

            $cursor->addMonth();
        }

        return $trend;
    }
}
