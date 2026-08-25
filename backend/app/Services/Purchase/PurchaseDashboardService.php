<?php

namespace App\Services\Purchase;

use App\Models\Purchase\GoodsReceipt;
use App\Models\Purchase\PurchaseApprovalRequest;
use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseContract;
use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoicePayment;
use App\Models\Purchase\PurchaseMomActionItem;
use App\Models\Purchase\PurchaseNcr;
use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseRenewal;
use App\Models\Purchase\PurchaseRequest;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Support\Purchase\GoodsReceiptStatus;
use App\Support\Purchase\PurchaseContractStatus;
use App\Support\Purchase\PurchaseDocumentStatus;
use App\Support\Purchase\PurchaseInvoiceStatus as InvStatus;
use App\Support\Purchase\PurchaseMomActionStatus;
use App\Support\Purchase\PurchaseOnboardingStatus as OnbStatus;
use App\Support\Purchase\PurchaseOrderStatus as PoStatus;
use App\Support\Purchase\PurchaseRegistrationType;
use App\Support\Purchase\PurchaseRequestStatus as PrStatus;
use App\Support\Purchase\PurchaseVendorStatus;
use Carbon\CarbonImmutable;

/**
 * Read-only aggregation across the whole procure-to-pay chain
 * (PR → PO → GRN → Invoice → Payment) for one tenant. Every query is
 * ->forTenant() scoped; nothing here mutates.
 */
class PurchaseDashboardService
{
    /** Contracts/documents inside this many days of expiry are worth chasing. */
    private const EXPIRY_WINDOW_DAYS = 30;

    public function __construct(
        private PurchaseComplianceService $compliance,
        private PurchaseVendorPerformanceService $performance,
    ) {}

    public function getDashboard(int $tenantId): array
    {
        // Rosters are the heavy computations; build each once and share.
        $complianceRoster = $this->compliance->roster($tenantId);
        $perfRoster = $this->performance->roster($tenantId);

        return [
            // Existing procure-to-pay (financial) view — unchanged.
            'kpis'           => $this->kpis($tenantId),
            'funnel'         => $this->funnel($tenantId),
            'monthly'        => $this->monthly($tenantId),
            'by_vendor'      => $this->topVendors($tenantId),
            'invoice_status' => $this->invoiceStatus($tenantId),
            'recent_invoices' => $this->recentInvoices($tenantId),
            // §4/§37 Vendor Control Tower — parity with TPV, sourced only from
            // purchase_* models. Gate/permit/strike KPIs are omitted (Purchase has
            // no gate-scan, permit or safety-strike concept); risk is by VPI band.
            'control_tower'  => $this->controlTower($tenantId, $complianceRoster, $perfRoster),
            'action_centre'  => $this->actionCentre($tenantId),
            'risk_breakdown' => $this->riskBreakdown($perfRoster),
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
            ->whereNotNull('purchase_vendor_id')
            ->selectRaw('purchase_vendor_id, SUM(total) as spend, COUNT(*) as invoices')
            ->groupBy('purchase_vendor_id')
            ->orderByDesc('spend')
            ->limit(5)
            ->with('vendor:id,company_name,purchase_vendor_code')
            ->get()
            ->map(fn ($r) => [
                'name'     => $r->vendor?->company_name ?? 'Unknown',
                'code'     => $r->vendor?->purchase_vendor_code,
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

    /* ── §4/§37 Vendor Control Tower (parity with TPV) ──────────────────────── */

    /**
     * Executive KPI layer. Mirrors the TPV Control Tower over purchase_* data.
     * Purchase has no gate/permit/strike concept, so on-site / active-permits /
     * total-strikes / gate-violations tiles are intentionally absent.
     */
    private function controlTower(int $tenantId, array $complianceRoster, array $perfRoster): array
    {
        $v = fn () => PurchaseVendor::forTenant($tenantId);
        $today = now()->toDateString();
        $soon = now()->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString();

        $active = PurchaseWorker::forTenant($tenantId)->where('status', 'Active');
        $activeCount = (clone $active)->count();

        // Readiness over the active workforce (0 workers → null, never a fake 0%).
        $medicalOk = (clone $active)->whereHas('medicals', fn ($q) => $q->where('fitness_status', 'Fit')
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $today)))->count();
        $trainingOk = (clone $active)->whereHas('trainings', fn ($q) => $q->where('status', 'Completed'))->count();

        $avgPerf = count($perfRoster)
            ? (int) round(array_sum(array_column($perfRoster, 'overall_score')) / count($perfRoster))
            : null;

        return [
            'vendors' => [
                'total'       => (clone $v())->count(),
                'active'      => (clone $v())->where('status', PurchaseVendorStatus::ACTIVE)->count(),
                'pending'     => (clone $v())->whereIn('status', [PurchaseVendorStatus::DRAFT, PurchaseVendorStatus::PENDING_APPROVAL])->count(),
                'on_hold'     => (clone $v())->where('status', PurchaseVendorStatus::ON_HOLD)->count(),
                'inactive'    => (clone $v())->where('status', PurchaseVendorStatus::INACTIVE)->count(),
                'blacklisted' => (clone $v())->where('status', PurchaseVendorStatus::BLACKLISTED)->count(),
                'rejected'    => (clone $v())->where('status', PurchaseVendorStatus::REJECTED)->count(),
                'temporary'   => (clone $v())->where('registration_type', PurchaseRegistrationType::TEMPORARY)->count(),
                'expiring'    => (clone $v())->whereNotNull('access_expires_at')
                    ->whereBetween('access_expires_at', [$today, $soon])->count(),
                'pending_onboarding' => PurchaseOnboarding::forTenant($tenantId)
                    ->whereNotIn('status', [OnbStatus::APPROVED, OnbStatus::REJECTED])->count(),
            ],
            'workforce' => [
                'total'  => PurchaseWorker::forTenant($tenantId)->count(),
                'active' => $activeCount,
            ],
            'readiness' => [
                'training_pct' => $activeCount ? (int) round($trainingOk / $activeCount * 100) : null,
                'medical_pct'  => $activeCount ? (int) round($medicalOk / $activeCount * 100) : null,
            ],
            'compliance' => $this->complianceKpis($tenantId, $complianceRoster),
            'open' => [
                'actions' => PurchaseMomActionItem::forTenant($tenantId)
                    ->whereIn('status', PurchaseMomActionStatus::OPEN_STATES)->count(),
                'overdue_actions' => PurchaseMomActionItem::forTenant($tenantId)
                    ->whereIn('status', PurchaseMomActionStatus::OPEN_STATES)
                    ->whereNotNull('target_date')->whereDate('target_date', '<', $today)->count(),
                'capas' => PurchaseCapa::forTenant($tenantId)->where('status', '!=', 'Verified')->count(),
                'ncrs'  => PurchaseNcr::forTenant($tenantId)->where('status', '!=', 'Closed')->count(),
            ],
            'performance' => ['avg_score' => $avgPerf],
        ];
    }

    /**
     * Compliance KPIs. Overall % is the mean of the §21 per-vendor register scores.
     * Purchase has no per-role PPE requirement matrix, so a worker is PPE-compliant
     * when they currently hold at least one issued item; "configured" = active workers.
     */
    private function complianceKpis(int $tenantId, array $complianceRoster): array
    {
        $overallPct = count($complianceRoster)
            ? (int) round(array_sum(array_column($complianceRoster, 'percent')) / count($complianceRoster))
            : null;

        $active = PurchaseWorker::forTenant($tenantId)->where('status', 'Active');
        $configured = (clone $active)->count();
        $equipped = (clone $active)->whereHas('ppeIssues', fn ($q) => $q->where('status', 'issued')
            ->whereRaw('qty > returned_qty'))->count();
        $ppePct = $configured > 0 ? (int) round($equipped / $configured * 100) : null;

        return [
            'ppe_pct'         => $ppePct,
            'ppe_missing'     => $configured - $equipped,
            'ppe_configured'  => $configured,
            'overall_pct'     => $overallPct,
            'vendors_tracked' => count($complianceRoster),
        ];
    }

    /**
     * Action Centre — the queue of what's waiting on a human. Same shape as TPV
     * (key/label/path/count, zero rows filtered out), minus permit expiry.
     */
    private function actionCentre(int $tenantId): array
    {
        $today = now()->toDateString();
        $soon = now()->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString();

        $rows = [
            ['key' => 'approvals', 'label' => 'Approvals pending', 'path' => '/app/purchase/approval-requests',
                'count' => PurchaseApprovalRequest::forTenant($tenantId)->where('status', 'Pending')->count()
                    + PurchaseOnboarding::forTenant($tenantId)
                        ->whereIn('status', [OnbStatus::SUBMITTED, OnbStatus::UNDER_REVIEW])->count()],
            ['key' => 'documents', 'label' => 'Documents pending review', 'path' => '/app/purchase/vendors',
                'count' => PurchaseDocument::forTenant($tenantId)
                    ->whereNotIn('status', [PurchaseDocumentStatus::APPROVED, PurchaseDocumentStatus::REJECTED])->count()],
            ['key' => 'docs_expiring', 'label' => 'Documents expiring (30d)', 'path' => '/app/purchase/vendors',
                'count' => PurchaseDocument::forTenant($tenantId)->where('status', PurchaseDocumentStatus::APPROVED)
                    ->whereNotNull('expires_at')->whereBetween('expires_at', [$today, $soon])->count()],
            ['key' => 'workforce', 'label' => 'Workforce pending approval', 'path' => '/app/purchase/workforce',
                'count' => PurchaseWorker::forTenant($tenantId)->where('status', 'Pending')->count()],
            ['key' => 'training', 'label' => 'Training pending', 'path' => '/app/purchase/workforce',
                'count' => PurchaseWorker::forTenant($tenantId)->where('status', 'Active')
                    ->whereDoesntHave('trainings', fn ($q) => $q->where('status', 'Completed'))->count()],
            ['key' => 'medical', 'label' => 'Medical expiring/expired', 'path' => '/app/purchase/workforce',
                'count' => PurchaseWorker::forTenant($tenantId)->where('status', 'Active')
                    ->whereDoesntHave('medicals', fn ($q) => $q->where('fitness_status', 'Fit')
                        ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $soon)))->count()],
            ['key' => 'ppe_pending', 'label' => 'PPE pending issue', 'path' => '/app/purchase/workforce',
                'count' => PurchaseWorker::forTenant($tenantId)->where('status', 'Active')
                    ->whereDoesntHave('ppeIssues', fn ($q) => $q->where('status', 'issued')->whereRaw('qty > returned_qty'))->count()],
            ['key' => 'capa_overdue', 'label' => 'CAPA overdue', 'path' => '/app/purchase/capa',
                'count' => PurchaseCapa::forTenant($tenantId)->where('status', '!=', 'Verified')
                    ->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count()],
            ['key' => 'ncr_overdue', 'label' => 'NCR overdue', 'path' => '/app/purchase/ncr',
                'count' => PurchaseNcr::forTenant($tenantId)->where('status', '!=', 'Closed')
                    ->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count()],
            ['key' => 'mom_pending', 'label' => 'MOM actions pending', 'path' => '/app/purchase/kickoff',
                'count' => PurchaseMomActionItem::forTenant($tenantId)->whereIn('status', PurchaseMomActionStatus::OPEN_STATES)->count()],
            ['key' => 'mom_actions_overdue', 'label' => 'Meeting actions overdue', 'path' => '/app/purchase/kickoff',
                'count' => PurchaseMomActionItem::forTenant($tenantId)->whereIn('status', PurchaseMomActionStatus::OPEN_STATES)
                    ->whereNotNull('target_date')->whereDate('target_date', '<', $today)->count()],
            ['key' => 'contract_expiry', 'label' => 'Contracts expiring (30d)', 'path' => '/app/purchase/contracts',
                'count' => PurchaseContract::forTenant($tenantId)->where('status', PurchaseContractStatus::ACTIVE)
                    ->whereNotNull('end_date')->whereBetween('end_date', [$today, $soon])->count()],
            ['key' => 'renewal_assessment_due', 'label' => 'Vendor renewals to assess', 'path' => '/app/purchase/renewals',
                'count' => PurchaseRenewal::forTenant($tenantId)->where('status', 'Pending')->count()],
        ];

        return array_values(array_filter($rows, fn ($r) => $r['count'] > 0));
    }

    /**
     * Risk view. Purchase vendors carry no risk_level, so risk is expressed by the
     * VPI performance band (A best → E worst) from the performance roster.
     */
    private function riskBreakdown(array $perfRoster): array
    {
        $counts = [];
        foreach ($perfRoster as $r) {
            $band = $r['band'] ?? 'Unrated';
            $counts[$band] = ($counts[$band] ?? 0) + 1;
        }

        return collect(['A', 'B', 'C', 'D', 'E'])
            ->map(fn ($b) => ['level' => $b, 'count' => (int) ($counts[$b] ?? 0)])
            ->all();
    }
}
