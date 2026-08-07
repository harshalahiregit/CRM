<?php

namespace App\Services\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Group-wise reporting for customer groups.
 *
 * The per-client equivalents live in ClientLedgerService, but this deliberately
 * does NOT loop over them: a group of 50 customers would fire hundreds of
 * queries. Every figure here is a grouped aggregate keyed by client_id, so the
 * cost is a fixed handful of queries per report regardless of group size.
 *
 * Cross-module reads (tickets, projects, contracts) are Schema::hasTable-guarded
 * so a workspace without those modules installed still renders the report
 * instead of erroring — the same convention ClientLedgerService uses.
 *
 * Date range filters on the INVOICE date. Ageing is always "as of today"
 * regardless of the range, because an ageing bucket relative to a historical
 * end-date would misrepresent what is actually collectable now.
 */
class ClientGroupReportService
{
    /** Report for one group (or every ungrouped/all customers when null). */
    public function groupReport(int $tenantId, ?int $groupId, ?string $from = null, ?string $to = null): array
    {
        $group = $groupId ? ClientGroup::where('tenant_id', $tenantId)->findOrFail($groupId) : null;

        $clients = $this->groupClients($tenantId, $groupId);
        $ids     = $clients->pluck('id')->all();

        $money    = $this->moneyByClient($tenantId, $ids, $from, $to);
        $tax      = $this->taxByClient($tenantId, $ids, $from, $to);
        $tds      = $this->tdsByClient($tenantId, $ids);
        $credit   = $this->creditByClient($tenantId, $ids);
        $activity = $this->activityByClient($tenantId, $ids);
        $ageing   = $this->ageingByClient($tenantId, $ids);

        $rows = $clients->map(function (Client $c) use ($money, $tax, $tds, $credit, $activity, $ageing) {
            $m = $money[$c->id] ?? null;
            $t = $tax[$c->id] ?? null;
            $a = $ageing[$c->id] ?? null;
            $gstTotal = (float) ($t->gst_total ?? 0);
            $gstPaid  = (float) ($t->gst_paid ?? 0);

            return [
                'id'               => $c->id,
                'company'          => $c->company,
                'active'           => (bool) $c->active,
                'invoice_count'    => (int) ($m->invoice_count ?? 0),
                'total_billed'     => (float) ($m->total_billed ?? 0),
                'total_paid'       => (float) ($m->total_paid ?? 0),
                'outstanding'      => (float) ($m->outstanding ?? 0),
                'available_credit' => (float) ($credit[$c->id] ?? 0),
                'gst_total'        => $gstTotal,
                'gst_paid'         => $gstPaid,
                'gst_unpaid'       => $gstTotal - $gstPaid,
                'tds_deducted'     => (float) ($tds[$c->id] ?? 0),
                'ageing'           => [
                    'current' => (float) ($a['current'] ?? 0),
                    'd30'     => (float) ($a['d30'] ?? 0),
                    'd60'     => (float) ($a['d60'] ?? 0),
                    'd90'     => (float) ($a['d90'] ?? 0),
                    'd90plus' => (float) ($a['d90plus'] ?? 0),
                ],
                'activity'         => $activity[$c->id] ?? $this->emptyActivity(),
            ];
        })->values();

        return [
            'group'   => $group ? ['id' => $group->id, 'name' => $group->name] : ['id' => null, 'name' => 'All Customers'],
            'range'   => ['from' => $from, 'to' => $to],
            'as_of'   => now()->toDateString(),
            'totals'  => $this->sumRows($rows),
            'clients' => $rows,
        ];
    }

    /** Every group side by side, for the comparison view. */
    public function allGroups(int $tenantId, ?string $from = null, ?string $to = null): array
    {
        $groups = ClientGroup::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']);

        $rows = $groups->map(function (ClientGroup $g) use ($tenantId, $from, $to) {
            $report = $this->groupReport($tenantId, $g->id, $from, $to);

            return ['id' => $g->id, 'name' => $g->name,
                'customer_count' => count($report['clients'])] + $report['totals'];
        });

        // Customers in no group at all — otherwise the comparison silently omits
        // revenue and the group totals don't reconcile with the customer list.
        $ungrouped = $this->ungroupedReport($tenantId, $from, $to);

        $grand = $this->sumRows(collect($rows)->push($ungrouped)->map(fn ($r) => $r));

        // sumRows() counts ROWS, which here are groups — and a customer in two
        // groups would be counted twice. The grand total wants distinct people,
        // so take it straight from the customer table.
        $grand['customer_count'] = Client::forTenant($tenantId)->count();
        $grand['group_count']    = $groups->count();

        return [
            'range'     => ['from' => $from, 'to' => $to],
            'as_of'     => now()->toDateString(),
            'groups'    => $rows->values(),
            'ungrouped' => $ungrouped,
            'grand'     => $grand,
        ];
    }

    private function ungroupedReport(int $tenantId, ?string $from, ?string $to): array
    {
        $ids = Client::forTenant($tenantId)
            ->whereDoesntHave('groups')
            ->pluck('id')->all();

        $money  = $this->moneyByClient($tenantId, $ids, $from, $to);
        $credit = $this->creditByClient($tenantId, $ids);
        $tax    = $this->taxByClient($tenantId, $ids, $from, $to);
        $tds    = $this->tdsByClient($tenantId, $ids);

        $rows = collect($ids)->map(fn ($id) => [
            'invoice_count'    => (int) ($money[$id]->invoice_count ?? 0),
            'total_billed'     => (float) ($money[$id]->total_billed ?? 0),
            'total_paid'       => (float) ($money[$id]->total_paid ?? 0),
            'outstanding'      => (float) ($money[$id]->outstanding ?? 0),
            'available_credit' => (float) ($credit[$id] ?? 0),
            'gst_total'        => (float) ($tax[$id]->gst_total ?? 0),
            'gst_paid'         => (float) ($tax[$id]->gst_paid ?? 0),
            'gst_unpaid'       => (float) ($tax[$id]->gst_total ?? 0) - (float) ($tax[$id]->gst_paid ?? 0),
            'tds_deducted'     => (float) ($tds[$id] ?? 0),
            'ageing'           => ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90plus' => 0],
            'activity'         => $this->emptyActivity(),
        ]);

        return ['id' => null, 'name' => 'Ungrouped', 'customer_count' => count($ids)] + $this->sumRows($rows);
    }

    /* ── Aggregates (one query each, keyed by client_id) ──────────────────── */

    private function groupClients(int $tenantId, ?int $groupId)
    {
        return Client::forTenant($tenantId)
            ->when($groupId, fn ($q) => $q->whereHas('groups', fn ($g) => $g->where('client_groups.id', $groupId)))
            ->orderBy('company')
            ->get(['id', 'company', 'active']);
    }

    private function moneyByClient(int $tenantId, array $ids, ?string $from, ?string $to)
    {
        if (! $ids) {
            return collect();
        }

        return DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->groupBy('client_id')
            ->select('client_id',
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw('SUM(total) as total_billed'),
                DB::raw('SUM(paid) as total_paid'),
                DB::raw('SUM(balance) as outstanding'))
            ->get()->keyBy('client_id');
    }

    private function taxByClient(int $tenantId, array $ids, ?string $from, ?string $to)
    {
        if (! $ids) {
            return collect();
        }

        return DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->groupBy('client_id')
            ->select('client_id',
                DB::raw('SUM(gst_amount) as gst_total'),
                DB::raw('SUM(CASE WHEN gst_paid = 1 THEN gst_amount ELSE 0 END) as gst_paid'))
            ->get()->keyBy('client_id');
    }

    private function tdsByClient(int $tenantId, array $ids)
    {
        if (! $ids || ! Schema::hasTable('sales_payments')) {
            return collect();
        }

        return DB::table('sales_payments')
            ->join('sales_invoices', 'sales_payments.invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.tenant_id', $tenantId)
            ->whereIn('sales_invoices.client_id', $ids)
            ->groupBy('sales_invoices.client_id')
            ->select('sales_invoices.client_id', DB::raw('SUM(sales_payments.tds_amount) as tds'))
            ->pluck('tds', 'client_id');
    }

    private function creditByClient(int $tenantId, array $ids)
    {
        if (! $ids || ! Schema::hasTable('credit_notes')) {
            return collect();
        }

        return DB::table('credit_notes')
            ->where('tenant_id', $tenantId)->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->groupBy('client_id')
            ->select('client_id', DB::raw('SUM(remaining) as remaining'))
            ->pluck('remaining', 'client_id');
    }

    /**
     * Outstanding split into ageing buckets by how overdue each invoice is
     * TODAY. Bucketed in PHP rather than SQL because the day-difference syntax
     * differs between SQLite and MySQL and this has to work on both.
     */
    private function ageingByClient(int $tenantId, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $rows = DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->where('balance', '>', 0)
            ->get(['client_id', 'balance', 'due_date']);

        $today = Carbon::today();
        $out = [];

        foreach ($rows as $r) {
            $days = $r->due_date ? $today->diffInDays(Carbon::parse($r->due_date), false) : 0;
            // diffInDays(due, false) is negative once the due date has passed.
            $overdue = $days < 0 ? abs($days) : 0;

            $bucket = match (true) {
                $overdue === 0  => 'current',
                $overdue <= 30  => 'd30',
                $overdue <= 60  => 'd60',
                $overdue <= 90  => 'd90',
                default         => 'd90plus',
            };

            $out[$r->client_id][$bucket] = ($out[$r->client_id][$bucket] ?? 0) + (float) $r->balance;
        }

        return $out;
    }

    /** Engagement counts from the other modules, each guarded by table presence. */
    private function activityByClient(int $tenantId, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $counts = [];

        // Linkage differs per table: proposals attach polymorphically
        // (rel_type/rel_id) rather than by client_id, so the column and any
        // extra predicate are declared per source.
        $sources = [
            'proposals'   => ['proposals',       'rel_id',      null, ['rel_type' => 'customer']],
            'estimates'   => ['estimates',       'client_id',   null, []],
            'invoices'    => ['sales_invoices',  'client_id',   null, []],
            'contracts'   => ['sales_contracts', 'client_id',   null, []],
            'tickets'     => ['tickets',         'customer_id', null, []],
            'open_tickets'=> ['tickets',         'customer_id', 'open', []],
            'projects'    => ['projects',        'customer_id', null, []],
        ];

        foreach ($sources as $key => [$table, $column, $openOnly, $where]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $q = DB::table($table)->where('tenant_id', $tenantId)->whereIn($column, $ids);
            foreach ($where as $col => $val) {
                if (Schema::hasColumn($table, $col)) {
                    $q->where($col, $val);
                }
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            if ($openOnly && Schema::hasColumn($table, 'status')) {
                $q->whereNotIn('status', ['closed', 'resolved', 'Closed', 'Resolved']);
            }

            foreach ($q->groupBy($column)->select($column, DB::raw('COUNT(*) as c'))->pluck('c', $column) as $cid => $c) {
                $counts[$cid][$key] = (int) $c;
            }
        }

        // Fill gaps so the shape is stable for every client.
        return collect($ids)->mapWithKeys(fn ($id) => [
            $id => array_merge($this->emptyActivity(), $counts[$id] ?? []),
        ])->all();
    }

    private function emptyActivity(): array
    {
        return ['proposals' => 0, 'estimates' => 0, 'invoices' => 0, 'contracts' => 0,
            'tickets' => 0, 'open_tickets' => 0, 'projects' => 0];
    }

    /** @param \Illuminate\Support\Collection<int, array> $rows */
    private function sumRows($rows): array
    {
        $sum = fn (string $k) => (float) $rows->sum(fn ($r) => $r[$k] ?? 0);

        return [
            'customer_count'   => $rows->count(),
            'invoice_count'    => (int) $rows->sum(fn ($r) => $r['invoice_count'] ?? 0),
            'total_billed'     => $sum('total_billed'),
            'total_paid'       => $sum('total_paid'),
            'outstanding'      => $sum('outstanding'),
            'available_credit' => $sum('available_credit'),
            'gst_total'        => $sum('gst_total'),
            'gst_paid'         => $sum('gst_paid'),
            'gst_unpaid'       => $sum('gst_unpaid'),
            'tds_deducted'     => $sum('tds_deducted'),
            'ageing'           => [
                'current' => (float) $rows->sum(fn ($r) => $r['ageing']['current'] ?? 0),
                'd30'     => (float) $rows->sum(fn ($r) => $r['ageing']['d30'] ?? 0),
                'd60'     => (float) $rows->sum(fn ($r) => $r['ageing']['d60'] ?? 0),
                'd90'     => (float) $rows->sum(fn ($r) => $r['ageing']['d90'] ?? 0),
                'd90plus' => (float) $rows->sum(fn ($r) => $r['ageing']['d90plus'] ?? 0),
            ],
            'activity'         => collect($this->emptyActivity())
                ->mapWithKeys(fn ($_, $k) => [$k => (int) $rows->sum(fn ($r) => $r['activity'][$k] ?? 0)])->all(),
        ];
    }
}
