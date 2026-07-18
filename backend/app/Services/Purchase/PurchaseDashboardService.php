<?php

namespace App\Services\Purchase;

use App\Models\Purchase\GoodsReceipt;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoicePayment;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseRequest;
use App\Support\Purchase\GoodsReceiptStatus;
use App\Support\Purchase\PurchaseInvoiceStatus as InvStatus;
use App\Support\Purchase\PurchaseOrderStatus as PoStatus;
use App\Support\Purchase\PurchaseRequestStatus as PrStatus;
use Carbon\CarbonImmutable;

/**
 * Read-only aggregation across the whole procure-to-pay chain
 * (PR → PO → GRN → Invoice → Payment) for one tenant. Every query is
 * ->forTenant() scoped; nothing here mutates.
 */
class PurchaseDashboardService
{
    public function getDashboard(int $tenantId): array
    {
        return [
            'kpis'           => $this->kpis($tenantId),
            'funnel'         => $this->funnel($tenantId),
            'monthly'        => $this->monthly($tenantId),
            'by_vendor'      => $this->topVendors($tenantId),
            'invoice_status' => $this->invoiceStatus($tenantId),
            'recent_invoices' => $this->recentInvoices($tenantId),
        ];
    }

    /** Headline numbers. */
    private function kpis(int $tenantId): array
    {
        $monthStart = CarbonImmutable::now()->startOfMonth();

        return [
            // Money actually paid out to vendors (all time + this month).
            'total_paid'   => (float) PurchaseInvoicePayment::forTenant($tenantId)->sum('amount'),
            'paid_mtd'     => (float) PurchaseInvoicePayment::forTenant($tenantId)
                                   ->where('payment_date', '>=', $monthStart)->sum('amount'),
            // Committed but not yet fully received (live orders).
            'open_po_value' => (float) PurchaseOrder::forTenant($tenantId)
                                   ->whereIn('status', [PoStatus::ISSUED, PoStatus::PARTIALLY_RECEIVED])->sum('total'),
            // Approved invoices still owed.
            'outstanding'  => (float) PurchaseInvoice::forTenant($tenantId)
                                   ->whereIn('status', InvStatus::PAYABLE)->sum('balance'),
            // Attention counters.
            'pr_pending'   => PurchaseRequest::forTenant($tenantId)->where('status', PrStatus::SUBMITTED)->count(),
            'po_open'      => PurchaseOrder::forTenant($tenantId)->whereIn('status', [PoStatus::ISSUED, PoStatus::PARTIALLY_RECEIVED])->count(),
            'overdue_invoices' => PurchaseInvoice::forTenant($tenantId)
                                   ->whereDate('due_date', '<', now())->where('balance', '>', 0)
                                   ->whereNotIn('status', [InvStatus::PAID, InvStatus::CANCELLED])->count(),
        ];
    }

    /**
     * The unifying view — count + value at each stage of the pipeline.
     * "Received" counts POs that have taken any delivery; "Invoiced" is all
     * invoices raised. Values are each stage's own money total.
     */
    private function funnel(int $tenantId): array
    {
        $pr = PurchaseRequest::forTenant($tenantId);
        $po = PurchaseOrder::forTenant($tenantId);
        $received = PurchaseOrder::forTenant($tenantId)
            ->whereIn('status', [PoStatus::PARTIALLY_RECEIVED, PoStatus::RECEIVED, PoStatus::CLOSED]);
        $inv = PurchaseInvoice::forTenant($tenantId)->where('status', '!=', InvStatus::CANCELLED);

        return [
            ['key' => 'requests', 'label' => 'Requests', 'count' => (clone $pr)->count(),       'value' => (float) (clone $pr)->sum('total')],
            ['key' => 'orders',   'label' => 'Orders',   'count' => (clone $po)->count(),       'value' => (float) (clone $po)->sum('total')],
            ['key' => 'received', 'label' => 'Received', 'count' => (clone $received)->count(), 'value' => (float) (clone $received)->sum('total')],
            ['key' => 'invoiced', 'label' => 'Invoiced', 'count' => (clone $inv)->count(),      'value' => (float) (clone $inv)->sum('total')],
        ];
    }

    /**
     * Last 6 months, two same-scale (₹) series: PO value ordered vs cash paid.
     * Computed per-month in PHP so it stays DB-agnostic (SQLite dev / MySQL prod).
     */
    private function monthly(int $tenantId): array
    {
        $out = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = CarbonImmutable::now()->startOfMonth()->subMonths($i);
            $end   = $start->endOfMonth();

            $ordered = (float) PurchaseOrder::forTenant($tenantId)
                ->whereIn('status', [PoStatus::ISSUED, PoStatus::PARTIALLY_RECEIVED, PoStatus::RECEIVED, PoStatus::CLOSED])
                ->whereBetween('issued_at', [$start, $end])->sum('total');
            $paid = (float) PurchaseInvoicePayment::forTenant($tenantId)
                ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])->sum('amount');

            $out[] = ['month' => $start->format('M'), 'ordered' => $ordered, 'paid' => $paid];
        }

        return $out;
    }

    /** Top 5 vendors by total invoiced (excludes cancelled invoices). */
    private function topVendors(int $tenantId): array
    {
        return PurchaseInvoice::forTenant($tenantId)
            ->where('status', '!=', InvStatus::CANCELLED)
            ->whereNotNull('vendor_id')
            ->selectRaw('vendor_id, SUM(total) as spend, COUNT(*) as invoices')
            ->groupBy('vendor_id')
            ->orderByDesc('spend')
            ->limit(5)
            ->with('vendor:id,company_name,vendor_code')
            ->get()
            ->map(fn ($r) => [
                'name'     => $r->vendor?->company_name ?? 'Unknown',
                'code'     => $r->vendor?->vendor_code,
                'spend'    => (float) $r->spend,
                'invoices' => (int) $r->invoices,
            ])->all();
    }

    /** Invoice count + total by status — drives the donut. */
    private function invoiceStatus(int $tenantId): array
    {
        $rows = PurchaseInvoice::forTenant($tenantId)
            ->selectRaw('status, COUNT(*) as count, SUM(total) as value')
            ->groupBy('status')->get()->keyBy('status');

        // Fixed order so the donut arcs are stable regardless of what exists.
        $order = [InvStatus::DRAFT, InvStatus::AWAITING_PAYMENT, InvStatus::PARTIALLY_PAID, InvStatus::PAID, InvStatus::CANCELLED];

        return collect($order)->map(fn ($s) => [
            'status' => $s,
            'label'  => InvStatus::label($s),
            'count'  => (int) ($rows[$s]->count ?? 0),
            'value'  => (float) ($rows[$s]->value ?? 0),
        ])->filter(fn ($r) => $r['count'] > 0)->values()->all();
    }

    private function recentInvoices(int $tenantId): array
    {
        return PurchaseInvoice::forTenant($tenantId)
            ->with('vendor:id,company_name')
            ->latest()->limit(6)->get()
            ->map(fn ($inv) => [
                'id'             => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'vendor'         => $inv->vendor?->company_name,
                'total'          => (float) $inv->total,
                'balance'        => (float) $inv->balance,
                'status'         => $inv->status,
                'is_overdue'     => $inv->is_overdue,
            ])->all();
    }
}
