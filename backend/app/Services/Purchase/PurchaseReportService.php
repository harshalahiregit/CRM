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
    public const PERIODS = ['all_time', 'this_month', 'last_month', 'this_quarter', 'this_year', 'last_year'];

    /**
     * Resolve a period key to [from, to] Y-m-d bounds. `all_time` → [null, null].
     */
    public function resolvePeriod(?string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'this_month'   => [$now->copy()->startOfMonth()->toDateString(),      $now->copy()->endOfMonth()->toDateString()],
            'last_month'   => [$now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'this_quarter' => [$now->copy()->startOfQuarter()->toDateString(),    $now->copy()->endOfQuarter()->toDateString()],
            'this_year'    => [$now->copy()->startOfYear()->toDateString(),       $now->copy()->endOfYear()->toDateString()],
            'last_year'    => [$now->copy()->subYear()->startOfYear()->toDateString(), $now->copy()->subYear()->endOfYear()->toDateString()],
            default        => [null, null],
        };
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
    public function itemCost(int $tenantId, ?string $from, ?string $to): array
    {
        $q = DB::table('purchase_order_items as oi')
            ->join('purchase_orders as po', 'po.id', '=', 'oi.purchase_order_id')
            ->whereNull('po.deleted_at')
            ->where('oi.tenant_id', $tenantId)
            ->whereIn('po.status', $this->committedOrderStatuses());

        $this->inPeriod($q, 'po', 'order_date', $from, $to);

        $rows = $q->groupBy('oi.description')
            ->orderByDesc(DB::raw('SUM(oi.amount)'))
            ->limit(200)
            ->get([
                'oi.description',
                DB::raw('SUM(oi.qty) as total_qty'),
                DB::raw('SUM(oi.amount) as total_cost'),
                DB::raw('AVG(oi.rate) as avg_rate'),
                DB::raw('COUNT(DISTINCT po.id) as order_count'),
            ]);

        return [
            'rows'  => $rows,
            'total' => (float) $rows->sum('total_cost'),
        ];
    }

    /** PO voucher report — one voucher line per purchase order. */
    public function poVoucher(int $tenantId, ?string $from, ?string $to): array
    {
        $q = DB::table('purchase_orders as po')
            ->leftJoin('purchase_vendors as v', 'v.id', '=', 'po.purchase_vendor_id')
            ->whereNull('po.deleted_at')
            ->where('po.tenant_id', $tenantId);

        $this->inPeriod($q, 'po', 'order_date', $from, $to);

        $rows = $q->orderByDesc('po.id')->limit(500)->get([
            'po.id', 'po.po_number', 'po.order_date', 'po.status', 'po.currency',
            'po.subtotal', 'po.tax_total', 'po.total',
            'v.company_name as vendor_name', 'v.purchase_vendor_code as vendor_code',
        ]);

        return [
            'rows'  => $rows,
            'total' => (float) $rows->sum('total'),
        ];
    }

    /** Purchase Order Report — spend grouped by vendor, with a status breakdown. */
    public function orders(int $tenantId, ?string $from, ?string $to): array
    {
        $byVendor = DB::table('purchase_orders as po')
            ->leftJoin('purchase_vendors as v', 'v.id', '=', 'po.purchase_vendor_id')
            ->whereNull('po.deleted_at')
            ->where('po.tenant_id', $tenantId);
        $this->inPeriod($byVendor, 'po', 'order_date', $from, $to);

        $vendors = $byVendor->groupBy('po.purchase_vendor_id', 'v.company_name', 'v.purchase_vendor_code')
            ->orderByDesc(DB::raw('SUM(po.total)'))
            ->get([
                'v.company_name as vendor_name', 'v.purchase_vendor_code as vendor_code',
                DB::raw('COUNT(po.id) as order_count'),
                DB::raw('SUM(po.total) as total_value'),
            ]);

        $byStatus = DB::table('purchase_orders as po')
            ->whereNull('po.deleted_at')
            ->where('po.tenant_id', $tenantId);
        $this->inPeriod($byStatus, 'po', 'order_date', $from, $to);

        $statuses = $byStatus->groupBy('po.status')
            ->get(['po.status', DB::raw('COUNT(po.id) as order_count'), DB::raw('SUM(po.total) as total_value')]);

        return [
            'rows'     => $vendors,
            'statuses' => $statuses,
            'total'    => (float) $vendors->sum('total_value'),
        ];
    }

    /** Purchase Invoices Report — billed vs paid vs outstanding. */
    public function invoices(int $tenantId, ?string $from, ?string $to): array
    {
        $q = DB::table('purchase_invoices as pi')
            ->leftJoin('purchase_vendors as v', 'v.id', '=', 'pi.purchase_vendor_id')
            ->whereNull('pi.deleted_at')
            ->where('pi.tenant_id', $tenantId);

        $this->inPeriod($q, 'pi', 'invoice_date', $from, $to);

        $rows = $q->orderByDesc('pi.id')->limit(500)->get([
            'pi.id', 'pi.invoice_number', 'pi.invoice_date', 'pi.due_date', 'pi.status', 'pi.currency',
            'pi.total', 'pi.amount_paid', 'pi.balance',
            'v.company_name as vendor_name', 'v.purchase_vendor_code as vendor_code',
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
    public function orderStats(int $tenantId, ?string $from, ?string $to, string $measure): array
    {
        // SQLite + MySQL both understand strftime/DATE_FORMAT differently, so bucket
        // in PHP off a lean projection — the volumes here are report-sized.
        $q = DB::table('purchase_orders as po')
            ->whereNull('po.deleted_at')
            ->where('po.tenant_id', $tenantId)
            ->whereIn('po.status', $this->committedOrderStatuses());

        $this->inPeriod($q, 'po', 'order_date', $from, $to);

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
