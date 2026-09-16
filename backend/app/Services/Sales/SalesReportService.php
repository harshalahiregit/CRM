<?php

namespace App\Services\Sales;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Sales report: what was sold, what was collected, and what is still owed.
 *
 * Distinct from the Sales DASHBOARD, which answers "how is this month going"
 * for a fixed, current period. A report is something you point at a range and
 * take to a meeting, so everything here is filterable and exportable, and every
 * figure is derived from the same scoped set of invoices rather than from
 * separate queries that can disagree with one another.
 *
 * Money is read from `sales_invoices`: `total` is what was billed, `paid` what
 * has come in against it, and `balance` what is still owed. Payments are read
 * separately because collection is a different question from billing — an
 * invoice raised in March and paid in May belongs to March's billing and May's
 * collection, and a report that conflates the two flatters whichever month you
 * happen to be looking at.
 */
class SalesReportService
{
    /** @param array{from?:string,to?:string,status?:string,client_id?:int|string|null,agent?:string|null} $filters */
    public function build(int $tenantId, array $filters = []): array
    {
        $invoices = $this->invoices($tenantId, $filters);

        return [
            'filters' => [
                'from'      => $filters['from'] ?? null,
                'to'        => $filters['to'] ?? null,
                'status'    => $filters['status'] ?? null,
                'client_id' => $filters['client_id'] ?? null,
                'agent'     => $filters['agent'] ?? null,
            ],
            'generated_at' => now()->toDateTimeString(),
            'totals'       => $this->totals($tenantId, $invoices, $filters),
            'by_status'    => $this->byStatus($invoices),
            'by_month'     => $this->byMonth($invoices),
            'by_customer'  => $this->byCustomer($invoices),
            'by_agent'     => $this->byAgent($invoices),
            'pipeline'     => $this->pipeline($tenantId, $filters),
        ];
    }

    /* ── Scope ──────────────────────────────────────────────────────────── */

    private function invoices(int $tenantId, array $filters): Collection
    {
        return DB::table('sales_invoices as i')
            ->leftJoin('clients as c', 'c.id', '=', 'i.client_id')
            ->where('i.tenant_id', $tenantId)
            ->whereNull('i.deleted_at')
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('i.date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('i.date', '<=', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('i.status', $v))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('i.client_id', (int) $v))
            ->when($filters['agent'] ?? null, fn ($q, $v) => $q->where('i.sale_agent', $v))
            ->selectRaw('i.id, i.number, i.date, i.due_date, i.status, i.sale_agent, i.client_id,
                         i.total, i.paid, i.balance, c.company as client')
            ->limit(20000)
            ->get()
            ->map(fn ($r) => [
                'id'       => $r->id,
                'number'   => $r->number,
                'date'     => $r->date ? Carbon::parse($r->date) : null,
                'due_date' => $r->due_date ? Carbon::parse($r->due_date) : null,
                'status'   => $r->status ?: 'Draft',
                'agent'    => $r->sale_agent ?: 'Unassigned',
                'client_id' => $r->client_id,
                'client'   => $r->client ?: 'Unknown customer',
                'total'    => (float) $r->total,
                'paid'     => (float) $r->paid,
                'balance'  => (float) $r->balance,
                // Owed AND past its due date — an unpaid invoice that is not yet
                // due is not a problem, and counting it as one makes the number
                // useless for chasing.
                'overdue'  => (float) $r->balance > 0.005
                    && $r->due_date && Carbon::parse($r->due_date)->isPast(),
            ]);
    }

    /* ── Aggregates ─────────────────────────────────────────────────────── */

    private function totals(int $tenantId, Collection $inv, array $filters): array
    {
        // Collection is asked separately: an invoice billed in March and paid in
        // May belongs to March's billing and May's collection.
        $collected = DB::table('sales_payments')
            ->where('tenant_id', $tenantId)
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->sum('amount');

        $billed = $inv->sum('total');

        return [
            'invoices'        => $inv->count(),
            'billed'          => round($billed, 2),
            'paid'            => round($inv->sum('paid'), 2),
            'outstanding'     => round($inv->sum('balance'), 2),
            'overdue_count'   => $inv->where('overdue', true)->count(),
            'overdue_value'   => round($inv->where('overdue', true)->sum('balance'), 2),
            'collected_in_period' => round((float) $collected, 2),
            'customers'       => $inv->pluck('client_id')->filter()->unique()->count(),
            'average_invoice' => $inv->count() ? round($billed / $inv->count(), 2) : 0.0,
            // What share of what was billed has actually arrived.
            'collection_rate' => $billed > 0 ? round($inv->sum('paid') / $billed * 100, 1) : null,
        ];
    }

    private function byStatus(Collection $inv): array
    {
        return $inv->groupBy('status')->map(fn (Collection $g, $status) => [
            'status'      => $status,
            'invoices'    => $g->count(),
            'billed'      => round($g->sum('total'), 2),
            'outstanding' => round($g->sum('balance'), 2),
        ])->sortByDesc('invoices')->values()->all();
    }

    private function byMonth(Collection $inv): array
    {
        return $inv->filter(fn ($r) => $r['date'])
            ->groupBy(fn ($r) => $r['date']->format('Y-m'))
            ->map(fn (Collection $g, $month) => [
                'month'       => $month,
                'label'       => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
                'invoices'    => $g->count(),
                'billed'      => round($g->sum('total'), 2),
                'paid'        => round($g->sum('paid'), 2),
                'outstanding' => round($g->sum('balance'), 2),
            ])->sortBy('month')->values()->all();
    }

    private function byCustomer(Collection $inv): array
    {
        return $inv->groupBy('client')->map(fn (Collection $g, $client) => [
            'client'      => $client,
            'client_id'   => $g->first()['client_id'],
            'invoices'    => $g->count(),
            'billed'      => round($g->sum('total'), 2),
            'paid'        => round($g->sum('paid'), 2),
            'outstanding' => round($g->sum('balance'), 2),
            'overdue'     => round($g->where('overdue', true)->sum('balance'), 2),
        ])->sortByDesc('billed')->values()->all();
    }

    private function byAgent(Collection $inv): array
    {
        return $inv->groupBy('agent')->map(fn (Collection $g, $agent) => [
            'agent'       => $agent,
            'invoices'    => $g->count(),
            'billed'      => round($g->sum('total'), 2),
            'paid'        => round($g->sum('paid'), 2),
            'outstanding' => round($g->sum('balance'), 2),
        ])->sortByDesc('billed')->values()->all();
    }

    /**
     * What has not become an invoice yet.
     *
     * Counted from the same date window so the whole page describes one period.
     */
    private function pipeline(int $tenantId, array $filters): array
    {
        $scoped = function (string $table, string $dateColumn) use ($tenantId, $filters) {
            return DB::table($table)->where('tenant_id', $tenantId)
                ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate($dateColumn, '>=', $v))
                ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate($dateColumn, '<=', $v));
        };

        return [
            'estimates' => [
                'count' => (clone $scoped('estimates', 'date'))->count(),
                'value' => round((float) (clone $scoped('estimates', 'date'))->sum('total'), 2),
            ],
            'proposals' => [
                'count' => (clone $scoped('proposals', 'date'))->count(),
                'value' => round((float) (clone $scoped('proposals', 'date'))->sum('total'), 2),
            ],
            'leads' => [
                'count' => (clone $scoped('leads', 'created_at'))->count(),
                'value' => round((float) (clone $scoped('leads', 'created_at'))->sum('lead_value'), 2),
            ],
        ];
    }
}
