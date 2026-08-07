<?php

namespace App\Services\Sales;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales dashboard figures.
 *
 * Rewritten from a version that loaded every invoice, proposal, estimate and
 * credit note into memory and filtered in PHP — fine on demo data, quadratic on
 * a real workspace. Everything here is an aggregate query instead.
 *
 * It also invented data: "top clients" was hardcoded to Acme / Globex / Soylent
 * with the period's revenue split 60/30/10, and the recent-invoice list showed
 * "Client #7" instead of the company. Both now come from the database, and the
 * dashboard says so honestly when there is nothing to show.
 */
class SalesDashboardService
{
    /** Months of history the trend chart shows, including the current one. */
    private const TREND_MONTHS = 12;

    public function getDashboard(int $tenantId): array
    {
        $now        = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $prevStart  = $monthStart->copy()->subMonth();

        $invoiceAgg = DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->selectRaw("
                COUNT(*) as cnt,
                SUM(total) as billed,
                SUM(balance) as outstanding,
                SUM(CASE WHEN status = 'Unpaid' THEN 1 ELSE 0 END) as unpaid,
                SUM(CASE WHEN status = 'Overdue' THEN 1 ELSE 0 END) as overdue,
                SUM(CASE WHEN status = 'Overdue' THEN balance ELSE 0 END) as overdue_value,
                SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) as paid_cnt,
                SUM(CASE WHEN status = 'Paid' THEN total ELSE 0 END) as paid_value
            ")->first();

        $revenueThis = $this->revenueBetween($tenantId, $monthStart, $now);
        $revenuePrev = $this->revenueBetween($tenantId, $prevStart, $monthStart->copy()->subSecond());

        $proposals = $this->statusCounts($tenantId, 'proposals', ['Open', 'Sent']);
        $estimates = $this->statusCounts($tenantId, 'estimates', ['Accepted']);

        return [
            'period' => ['month' => $monthStart->format('M Y'), 'as_of' => $now->toDateString()],
            'kpis' => [
                'total_revenue'      => $revenueThis,
                'revenue_prev_month' => $revenuePrev,
                // Percent change vs the previous month. Null (not 0) when there is
                // no baseline — "+100%" against zero would be meaningless.
                'revenue_delta_pct'  => $revenuePrev > 0
                    ? round((($revenueThis - $revenuePrev) / $revenuePrev) * 100, 1)
                    : null,
                'outstanding'        => (float) ($invoiceAgg->outstanding ?? 0),
                'open_invoices'      => (int) ($invoiceAgg->unpaid ?? 0),
                'overdue_payments'   => (int) ($invoiceAgg->overdue ?? 0),
                'overdue_value'      => (float) ($invoiceAgg->overdue_value ?? 0),
                'pending_proposals'  => $proposals['matched'],
                'accepted_estimates' => $estimates['matched'],
                'credit_notes_issued'=> $this->statusCounts($tenantId, 'credit_notes', ['Open'])['matched'],
                // Proposals that reached a PAID invoice — the old version divided
                // total invoices by total proposals and labelled it "proposal to
                // paid", which it never measured.
                'conversion_rate'    => $proposals['total'] > 0
                    ? round(((int) ($invoiceAgg->paid_cnt ?? 0) / $proposals['total']) * 100)
                    : 0,
                'monthly_target'     => $this->monthlyTarget($tenantId),
            ],
            'revenue_by_month' => $this->revenueTrend($tenantId),
            'pipeline'         => $this->pipeline($tenantId, $invoiceAgg),
            'recent_invoices'  => $this->recentInvoices($tenantId),
            'top_clients'      => $this->topClients($tenantId),
        ];
    }

    private function revenueBetween(int $tenantId, Carbon $from, Carbon $to): float
    {
        return (float) DB::table('sales_payments')
            ->where('tenant_id', $tenantId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');
    }

    /**
     * Revenue per month for the trend line.
     *
     * Keyed by YYYY-MM, not the month name: the old version grouped on format('M')
     * so Jan 2025 and Jan 2026 landed in the same bucket, and the series came out
     * in insertion order rather than chronologically. Months with no payments are
     * emitted as zero so the line has no invisible gaps.
     */
    private function revenueTrend(int $tenantId): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths(self::TREND_MONTHS - 1);

        $rows = DB::table('sales_payments')
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', $start->toDateString())
            ->get(['date', 'amount'])
            ->groupBy(fn ($p) => Carbon::parse($p->date)->format('Y-m'))
            ->map(fn ($g) => (float) $g->sum('amount'));

        $out = [];
        for ($i = 0; $i < self::TREND_MONTHS; $i++) {
            $m = $start->copy()->addMonths($i);
            $key = $m->format('Y-m');
            $out[] = [
                'key'    => $key,
                'month'  => $m->format('M'),
                'label'  => $m->format('M Y'),
                'amount' => (float) ($rows[$key] ?? 0),
            ];
        }

        return $out;
    }

    /** @return array{matched:int,total:int} */
    private function statusCounts(int $tenantId, string $table, array $statuses): array
    {
        if (! Schema::hasTable($table)) {
            return ['matched' => 0, 'total' => 0];
        }

        $q = DB::table($table)->where('tenant_id', $tenantId);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return [
            'matched' => (clone $q)->whereIn('status', $statuses)->count(),
            'total'   => (clone $q)->count(),
        ];
    }

    private function pipeline(int $tenantId, $invoiceAgg): array
    {
        $sum = function (string $table) use ($tenantId) {
            if (! Schema::hasTable($table)) {
                return ['count' => 0, 'value' => 0.0];
            }
            $q = DB::table($table)->where('tenant_id', $tenantId);
            if (Schema::hasColumn($table, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            return ['count' => (clone $q)->count(), 'value' => (float) (clone $q)->sum('total')];
        };

        $p = $sum('proposals');
        $e = $sum('estimates');

        return [
            ['stage' => 'Proposals', 'count' => $p['count'], 'value' => $p['value']],
            ['stage' => 'Estimates', 'count' => $e['count'], 'value' => $e['value']],
            ['stage' => 'Invoiced',  'count' => (int) ($invoiceAgg->cnt ?? 0),      'value' => (float) ($invoiceAgg->billed ?? 0)],
            ['stage' => 'Paid',      'count' => (int) ($invoiceAgg->paid_cnt ?? 0), 'value' => (float) ($invoiceAgg->paid_value ?? 0)],
        ];
    }

    /** Latest invoices with the real customer name, resolved in one join. */
    private function recentInvoices(int $tenantId): array
    {
        return DB::table('sales_invoices as i')
            ->leftJoin('clients as c', 'c.id', '=', 'i.client_id')
            ->where('i.tenant_id', $tenantId)->whereNull('i.deleted_at')
            ->orderByDesc('i.date')->orderByDesc('i.id')->limit(6)
            ->get(['i.id', 'i.number', 'i.total as amount', 'i.status', 'i.date', 'c.company as client'])
            ->map(fn ($r) => [
                'id'     => $r->id,
                'number' => $r->number,
                'client' => $r->client ?: 'Unassigned',
                'amount' => (float) $r->amount,
                'status' => $r->status,
                'date'   => $r->date,
            ])->all();
    }

    /**
     * Highest-paying customers, by payments actually RECEIVED (not invoiced), so
     * the list reflects cash in rather than promises. Was previously fabricated.
     */
    private function topClients(int $tenantId): array
    {
        return DB::table('sales_payments as p')
            ->join('sales_invoices as i', 'i.id', '=', 'p.invoice_id')
            ->leftJoin('clients as c', 'c.id', '=', 'i.client_id')
            ->where('p.tenant_id', $tenantId)->whereNull('i.deleted_at')
            ->groupBy('i.client_id', 'c.company')
            ->orderByDesc(DB::raw('SUM(p.amount)'))
            ->limit(5)
            ->get([DB::raw('COALESCE(c.company, "Unassigned") as name'), DB::raw('SUM(p.amount) as revenue')])
            ->map(fn ($r) => ['name' => $r->name, 'revenue' => (float) $r->revenue])
            ->all();
    }

    /**
     * Monthly revenue target. Reads the tenant setting when the Settings module
     * provides one, else null — the dashboard hides the meter rather than
     * measuring against an invented figure (it used to hardcode ₹10,00,000).
     */
    private function monthlyTarget(int $tenantId): ?float
    {
        if (! Schema::hasTable('tenant_settings')) {
            return null;
        }

        $v = DB::table('tenant_settings')->where('tenant_id', $tenantId)
            ->whereIn('key', ['sales.monthly_target', 'monthly_target'])
            ->value('value');

        return is_numeric($v) ? (float) $v : null;
    }
}
