<?php

namespace App\Services\Purchase;

use App\Support\Purchase\PurchaseOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Reports — read-only aggregations over the Purchase-owned tables
 * (purchase_orders, purchase_order_items, purchase_invoices, purchase_vendors).
 * Every query is tenant-scoped and joins purchase_vendor_id only — no shared
 * Vendor, no TPV. Nothing here writes.
 */
class PurchaseReportService
{
    /**
     * '3' / '6' / '12' are rolling windows ending this month; 'custom' takes the
     * from/to the caller supplies.
     */
    public const PERIODS = [
        'all_time', 'this_month', 'last_month', 'this_quarter', 'this_year', 'last_year',
        '3', '6', '12', 'custom',
    ];

    /**
     * Resolve a period key to [from, to] Y-m-d bounds. `all_time` → [null, null].
     *
     * A custom range keeps whatever bound was given: from-only and to-only are
     * both meaningful, so a missing half is left open rather than guessed.
     */
    public function resolvePeriod(?string $period, ?string $customFrom = null, ?string $customTo = null): array
    {
        $now = Carbon::now();

        // N months back, inclusive of the current month — 3 means "this month
        // and the two before it", which is what the picker's subtext says.
        $rolling = fn (int $months) => [
            $now->copy()->subMonthsNoOverflow($months - 1)->startOfMonth()->toDateString(),
            $now->copy()->endOfMonth()->toDateString(),
        ];

        return match ($period) {
            'this_month'   => [$now->copy()->startOfMonth()->toDateString(),      $now->copy()->endOfMonth()->toDateString()],
            'last_month'   => [$now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'this_quarter' => [$now->copy()->startOfQuarter()->toDateString(),    $now->copy()->endOfQuarter()->toDateString()],
            'this_year'    => [$now->copy()->startOfYear()->toDateString(),       $now->copy()->endOfYear()->toDateString()],
            'last_year'    => [$now->copy()->subYear()->startOfYear()->toDateString(), $now->copy()->subYear()->endOfYear()->toDateString()],
            '3'            => $rolling(3),
            '6'            => $rolling(6),
            '12'           => $rolling(12),
            'custom'       => [$customFrom ?: null, $customTo ?: null],
            default        => [null, null],
        };
    }

    /**
     * The values the report filters offer — every one drawn from real rows, so a
     * filter can never be set to something nothing will match.
     */
    public function filterOptions(int $tenantId): array
    {
        $items = DB::table('purchase_order_items as oi')
            ->join('purchase_orders as po', 'po.id', '=', 'oi.purchase_order_id')
            ->whereNull('po.deleted_at')
            ->where('oi.tenant_id', $tenantId)
            ->whereNotNull('oi.description')
            ->distinct()
            ->orderBy('oi.description')
            ->limit(500)
            ->pluck('oi.description');

        $currencies = DB::table('purchase_orders')
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('currency')
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency');

        $years = DB::table('purchase_orders')
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenantId)
            ->get([DB::raw('COALESCE(order_date, created_at) as d')])
            ->map(fn ($r) => (int) Carbon::parse($r->d)->format('Y'))
            ->unique()->sortDesc()->values();

        return [
            'items'      => $items,
            'currencies' => $currencies,
            // Fall back to the current year so the picker is never empty.
            'years'      => $years->isEmpty() ? [(int) Carbon::now()->format('Y')] : $years,
        ];
    }

    /**
     * A full Jan–Dec series for one year. Months with no orders are real zeros,
     * not gaps — an empty March is information, so the column is drawn at 0.
     */
    private function orderStatsForYear(int $tenantId, int $year, string $measure, array $f): array
    {
        $q = DB::table('purchase_orders as po')
            ->whereNull('po.deleted_at')
            ->where('po.tenant_id', $tenantId)
            ->whereIn('po.status', $this->committedOrderStatuses());

        $this->inPeriod($q, 'po', 'order_date', "{$year}-01-01", "{$year}-12-31");
        $this->applyFilters($q, $f);

        $raw = $q->get([DB::raw('COALESCE(po.order_date, po.created_at) as d'), 'po.total']);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['count' => 0, 'value' => 0.0];
        }
        foreach ($raw as $row) {
            $m = (int) Carbon::parse($row->d)->format('n');
            $months[$m]['count']++;
            $months[$m]['value'] += (float) $row->total;
        }

        $points = [];
        for ($m = 1; $m <= 12; $m++) {
            $points[] = [
                'bucket' => sprintf('%d-%02d', $year, $m),
                'label'  => Carbon::createFromDate($year, $m, 1)->format('M'),
                'value'  => $measure === 'count' ? $months[$m]['count'] : round($months[$m]['value'], 2),
            ];
        }

        return [
            'measure' => $measure,
            'year'    => $year,
            'points'  => $points,
            'total'   => array_sum(array_column($points, 'value')),
        ];
    }

    /** Currency narrowing, shared by every money report. */
    private function applyFilters($query, array $f, string $alias = 'po'): void
    {
        if (! empty($f['currency'])) {
            $query->where($alias.'.currency', $f['currency']);
        }
    }

    /** Apply the period window to a query on `$dateCol`, falling back to created_at. */
    private function inPeriod($query, string $table, string $dateCol, ?string $from, ?string $to)
    {
        $expr = DB::raw("COALESCE({$table}.{$dateCol}, {$table}.created_at)");

        if ($from) {
            $query->whereDate($expr, '>=', $from);
        }
        if ($to) {
            $query->whereDate($expr, '<=', $to);
        }

        return $query;
    }

    /** Orders that actually represent committed spend (drafts/cancelled excluded). */
    private function committedOrderStatuses(): array
    {
        return [
            PurchaseOrderStatus::ISSUED,
            PurchaseOrderStatus::PARTIALLY_RECEIVED,
            PurchaseOrderStatus::RECEIVED,
            PurchaseOrderStatus::CLOSED,
        ];
    }

    /* ── Report by table ─────────────────────────────────────────────────── */

    /** Cost of goods for each item — ordered qty and spend, grouped by item. */
    public function itemCost(int $tenantId, ?string $from, ?string $to, array $f = []): array
    {
        $q = DB::table('purchase_order_items as oi')
            ->join('purchase_orders as po', 'po.id', '=', 'oi.purchase_order_id')
            ->whereNull('po.deleted_at')
            ->where('oi.tenant_id', $tenantId)
            ->whereIn('po.status', $this->committedOrderStatuses());

        $this->inPeriod($q, 'po', 'order_date', $from, $to);
        $this->applyFilters($q, $f);

        if (! empty($f['items'])) {
            $q->whereIn('oi.description', (array) $f['items']);
        }

        // One line per ordered item, with the code from the catalogue where the
        // line was raised against one. Matches the reference report's grain.
        $rows = $q->leftJoin('purchase_catalog_items as ci', 'ci.id', '=', 'oi.catalog_item_id')
            ->orderByDesc('po.id')
            ->limit(500)
            ->get([
                'oi.id',
                'ci.sku as product_code',
                'oi.description as product_name',
                'po.po_number',
                'po.order_date',
                'oi.qty', 'oi.rate',
                'oi.amount as subtotal',
            ]);

        return [
            'rows'  => $rows,
            'total' => (float) $rows->sum('subtotal'),
        ];
    }

    /** PO voucher report — one voucher line per purchase order. */
    public function poVoucher(int $tenantId, ?string $from, ?string $to, array $f = []): array
    {
        $q = DB::table('purchase_orders as po')
            ->leftJoin('purchase_vendors as v', 'v.id', '=', 'po.purchase_vendor_id')
            ->whereNull('po.deleted_at')
            ->where('po.tenant_id', $tenantId);

        $this->inPeriod($q, 'po', 'order_date', $from, $to);
        $this->applyFilters($q, $f);

        // Delivery is derived from the lines, not stored: an order is delivered
        // when every line is fully received. Payment comes from its invoices.
        $rows = $q->orderByDesc('po.id')->limit(500)->get([
            'po.id', 'po.po_number', 'po.order_date', 'po.status', 'po.currency',
            'po.department', 'po.subtotal', 'po.tax_total', 'po.total',
            'v.company_name as vendor_name', 'v.purchase_vendor_code as vendor_code',
            DB::raw('(SELECT COUNT(*) FROM purchase_order_items oi WHERE oi.purchase_order_id = po.id) as line_count'),
            DB::raw('(SELECT COUNT(*) FROM purchase_order_items oi WHERE oi.purchase_order_id = po.id AND oi.received_qty >= oi.qty) as received_lines'),
            DB::raw('(SELECT COALESCE(SUM(pi.total), 0) FROM purchase_invoices pi WHERE pi.purchase_order_id = po.id AND pi.deleted_at IS NULL) as invoiced_total'),
            DB::raw('(SELECT COALESCE(SUM(pi.amount_paid), 0) FROM purchase_invoices pi WHERE pi.purchase_order_id = po.id AND pi.deleted_at IS NULL) as paid_total'),
        ])->map(function ($r) {
            $r->delivery_status = $r->line_count == 0 ? 'No lines'
                : ($r->received_lines >= $r->line_count ? 'Delivered'
                    : ($r->received_lines > 0 ? 'Partially delivered' : 'Pending'));

            $r->payment_status = $r->invoiced_total <= 0 ? 'Not invoiced'
                : ($r->paid_total >= $r->invoiced_total ? 'Paid'
                    : ($r->paid_total > 0 ? 'Partially paid' : 'Unpaid'));

            return $r;
        });

        return [
            'rows'  => $rows,
            'total' => (float) $rows->sum('total'),
        ];
    }

    /** Purchase Order Report — one row per PO, plus a status breakdown. */
    public function orders(int $tenantId, ?string $from, ?string $to, array $f = []): array
    {
        $byVendor = DB::table('purchase_orders as po')
            ->leftJoin('purchase_vendors as v', 'v.id', '=', 'po.purchase_vendor_id')
            ->whereNull('po.deleted_at')
            ->where('po.tenant_id', $tenantId);
        $this->inPeriod($byVendor, 'po', 'order_date', $from, $to);
        $this->applyFilters($byVendor, $f);

        // One row per purchase order — PO, date, department, vendor, approval
        // status, and the value split into net / tax / gross.
        $vendors = $byVendor->orderByDesc('po.id')->limit(500)->get([
            'po.id', 'po.po_number', 'po.order_date', 'po.department', 'po.status', 'po.currency',
            'po.subtotal as po_value', 'po.tax_total as tax_value', 'po.total as total_value',
            'v.company_name as vendor_name', 'v.purchase_vendor_code as vendor_code',
        ]);

        $byStatus = DB::table('purchase_orders as po')
            ->whereNull('po.deleted_at')
            ->where('po.tenant_id', $tenantId);
        $this->inPeriod($byStatus, 'po', 'order_date', $from, $to);
        $this->applyFilters($byStatus, $f);

        $statuses = $byStatus->groupBy('po.status')
            ->get(['po.status', DB::raw('COUNT(po.id) as order_count'), DB::raw('SUM(po.total) as total_value')]);

        return [
            'rows'     => $vendors,
            'statuses' => $statuses,
            'total'    => (float) $vendors->sum('total_value'),
            'total_po' => (float) $vendors->sum('po_value'),
            'total_tax'=> (float) $vendors->sum('tax_value'),
        ];
    }

    /** Purchase Invoices Report — billed vs paid vs outstanding. */
    public function invoices(int $tenantId, ?string $from, ?string $to, array $f = []): array
    {
        $q = DB::table('purchase_invoices as pi')
            ->leftJoin('purchase_vendors as v', 'v.id', '=', 'pi.purchase_vendor_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
            ->leftJoin('purchase_contracts as pc', 'pc.id', '=', 'po.purchase_contract_id')
            ->whereNull('pi.deleted_at')
            ->where('pi.tenant_id', $tenantId);

        $this->inPeriod($q, 'pi', 'invoice_date', $from, $to);
        $this->applyFilters($q, $f, 'pi');

        $rows = $q->orderByDesc('pi.id')->limit(500)->get([
            'pi.id', 'pi.invoice_number', 'pi.invoice_date', 'pi.due_date', 'pi.status', 'pi.currency',
            'pi.subtotal as invoice_amount', 'pi.tax_total as tax_value',
            'pi.total', 'pi.amount_paid', 'pi.balance',
            'v.company_name as vendor_name', 'v.purchase_vendor_code as vendor_code',
            'po.po_number', 'pc.contract_number',
        ]);

        return [
            'rows'    => $rows,
            'total'   => (float) $rows->sum('total'),
            'paid'    => (float) $rows->sum('amount_paid'),
            'balance' => (float) $rows->sum('balance'),
        ];
    }

    /* ── Charts ──────────────────────────────────────────────────────────── */

    /**
     * Monthly buckets for the two chart reports.
     * $measure: 'count' → number of purchase orders · 'cost' → total value.
     */
    public function orderStats(int $tenantId, ?string $from, ?string $to, string $measure, array $f = []): array
    {
        // The two chart reports are driven by a YEAR, not a period, and always
        // plot Jan–Dec so the columns line up between years.
        if (! empty($f['year'])) {
            return $this->orderStatsForYear($tenantId, (int) $f['year'], $measure, $f);
        }

        // SQLite + MySQL both understand strftime/DATE_FORMAT differently, so bucket
        // in PHP off a lean projection — the volumes here are report-sized.
        $q = DB::table('purchase_orders as po')
            ->whereNull('po.deleted_at')
            ->where('po.tenant_id', $tenantId)
            ->whereIn('po.status', $this->committedOrderStatuses());

        $this->inPeriod($q, 'po', 'order_date', $from, $to);
        $this->applyFilters($q, $f);

        $raw = $q->get([DB::raw('COALESCE(po.order_date, po.created_at) as d'), 'po.total']);

        $buckets = [];
        foreach ($raw as $row) {
            $key = Carbon::parse($row->d)->format('Y-m');
            if (! isset($buckets[$key])) {
                $buckets[$key] = ['bucket' => $key, 'count' => 0, 'value' => 0.0];
            }
            $buckets[$key]['count']++;
            $buckets[$key]['value'] += (float) $row->total;
        }

        ksort($buckets);
        $points = array_values(array_map(fn ($b) => [
            'bucket' => $b['bucket'],
            'label'  => Carbon::createFromFormat('Y-m', $b['bucket'])->format('M Y'),
            'value'  => $measure === 'count' ? $b['count'] : round($b['value'], 2),
        ], $buckets));

        return [
            'measure' => $measure,
            'points'  => $points,
            'total'   => array_sum(array_column($points, 'value')),
        ];
    }
}
