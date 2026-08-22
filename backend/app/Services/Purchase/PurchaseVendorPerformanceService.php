<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseInspection;
use App\Models\Purchase\PurchaseNcr;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorCompliance;
use App\Models\Purchase\PurchaseVendorViolation;
use App\Support\Purchase\PurchaseComplianceCatalog as Catalog;

/**
 * Purchase Vendor Performance Index — the Purchase-side mirror of
 * TpvVendorPerformanceService (parity rule). Purchase has no VRS scorecard, so
 * the index is computed directly from the mirrored governance engines:
 * compliance, quality (NCRs), CAPA closure, conduct (violations), inspections
 * and documentation currency — six weighted dimensions → 0-100 → A-E band.
 *
 * Read-only and additive. Weights/bands in config/purchase_vpi.php.
 */
class PurchaseVendorPerformanceService
{
    public const DIMENSIONS = ['compliance', 'quality', 'capa', 'conduct', 'inspection', 'documentation'];

    public function compute(PurchaseVendor $vendor): array
    {
        $ded = config('purchase_vpi.deductions');

        $dims = [
            'compliance'    => $this->complianceDim($vendor),
            'quality'       => $this->ncrDim($vendor, $ded),
            'capa'          => $this->capaDim($vendor, $ded),
            'conduct'       => $this->conductDim($vendor, $ded),
            'inspection'    => $this->inspectionDim($vendor),
            'documentation' => $this->documentationDim($vendor),
        ];

        $w = config('purchase_vpi.weights');
        $overall = 0.0;
        foreach ($w as $k => $weight) {
            $overall += ($dims[$k]['score'] ?? 0) * $weight;
        }
        $overall = (int) round($overall);

        return [
            'vendor_id'     => $vendor->id,
            'company_name'  => $vendor->company_name,
            'vendor_code'   => $vendor->purchase_vendor_code,
            'overall_score' => $overall,
            'band'          => $this->band($overall),
            'dimensions'    => $dims,
            'weights'       => $w,
            'computed_at'   => now()->toIso8601String(),
        ];
    }

    /** VPI leaderboard across all tenant vendors — worst index first. */
    public function roster(int $tenantId): array
    {
        $rows = PurchaseVendor::forTenant($tenantId)->get()
            ->map(function (PurchaseVendor $v) {
                $c = $this->compute($v);

                return [
                    'vendor_id'     => $v->id,
                    'vendor'        => $v->company_name,
                    'vendor_code'   => $v->purchase_vendor_code,
                    'status'        => $v->status,
                    'overall_score' => $c['overall_score'],
                    'band'          => $c['band'],
                    'dimensions'    => array_map(fn ($d) => $d['score'], $c['dimensions']),
                ];
            })->all();

        usort($rows, fn ($a, $b) => $a['overall_score'] <=> $b['overall_score']);

        return $rows;
    }

    /* ── Dimensions ─────────────────────────────────────────────────────── */

    private function complianceDim(PurchaseVendor $vendor): array
    {
        $rows = PurchaseVendorCompliance::where('purchase_vendor_id', $vendor->id)->get(['status', 'valid_until']);
        if ($rows->isEmpty()) {
            return ['score' => 100, 'label' => 'Compliance', 'detail' => ['tracked' => 0]];
        }
        $ok = $rows->filter(fn ($r) => in_array($r->effective_status, Catalog::OK_STATUSES, true))->count();
        $pct = (int) round($ok / $rows->count() * 100);

        return ['score' => $pct, 'label' => 'Compliance', 'detail' => ['tracked' => $rows->count(), 'ok' => $ok]];
    }

    private function ncrDim(PurchaseVendor $vendor, array $ded): array
    {
        $open = PurchaseNcr::where('purchase_vendor_id', $vendor->id)->where('status', '!=', 'Closed');
        $openCount = (clone $open)->count();
        $overdue = (clone $open)->whereNotNull('due_date')->whereDate('due_date', '<', now())->count();
        $score = max(0, 100 - $openCount * $ded['ncr_open'] - $overdue * $ded['ncr_overdue']);

        return ['score' => $score, 'label' => 'Quality (NCR)', 'detail' => ['open' => $openCount, 'overdue' => $overdue]];
    }

    private function capaDim(PurchaseVendor $vendor, array $ded): array
    {
        $open = PurchaseCapa::where('purchase_vendor_id', $vendor->id)->where('status', '!=', 'Verified');
        $openCount = (clone $open)->count();
        $overdue = (clone $open)->whereNotNull('due_date')->whereDate('due_date', '<', now())->count();
        $score = max(0, 100 - $openCount * $ded['capa_open'] - $overdue * $ded['capa_overdue']);

        return ['score' => $score, 'label' => 'CAPA closure', 'detail' => ['open' => $openCount, 'overdue' => $overdue]];
    }

    private function conductDim(PurchaseVendor $vendor, array $ded): array
    {
        $points = (int) PurchaseVendorViolation::where('purchase_vendor_id', $vendor->id)->where('status', 'Open')->sum('points');
        $score = max(0, 100 - $points * $ded['violation_point']);

        return ['score' => $score, 'label' => 'Conduct', 'detail' => ['violation_points' => $points]];
    }

    private function inspectionDim(PurchaseVendor $vendor): array
    {
        $scores = PurchaseInspection::where('purchase_vendor_id', $vendor->id)
            ->whereIn('status', ['Completed', 'Closed'])
            ->whereNotNull('score')
            ->pluck('score');
        if ($scores->isEmpty()) {
            return ['score' => 100, 'label' => 'Inspection', 'detail' => ['conducted' => 0, 'note' => 'No conducted inspections']];
        }

        return ['score' => (int) round($scores->avg()), 'label' => 'Inspection', 'detail' => ['conducted' => $scores->count(), 'avg' => round($scores->avg(), 1)]];
    }

    private function documentationDim(PurchaseVendor $vendor): array
    {
        $window = (int) config('purchase_vpi.doc_expiring_window_days', 30);
        $docs = PurchaseDocument::where('purchase_vendor_id', $vendor->id)->get(['status', 'expires_at']);
        if ($docs->isEmpty()) {
            return ['score' => 100, 'label' => 'Documentation', 'detail' => ['docs' => 0]];
        }
        $today = now();
        $expired = 0; $expiring = 0;
        foreach ($docs as $d) {
            if (! $d->expires_at) {
                continue;
            }
            if ($d->expires_at->isPast()) {
                $expired++;
            } elseif ($d->expires_at->lte($today->copy()->addDays($window))) {
                $expiring++;
            }
        }
        $total = $docs->count();
        $score = (int) round(max(0, ($total - $expired - $expiring * 0.5) / $total) * 100);

        return ['score' => $score, 'label' => 'Documentation', 'detail' => ['docs' => $total, 'expired' => $expired, 'expiring' => $expiring]];
    }

    private function band(int $overall): string
    {
        $b = config('purchase_vpi.bands');
        foreach (['A', 'B', 'C', 'D'] as $letter) {
            if ($overall >= $b[$letter]) {
                return $letter;
            }
        }

        return 'E';
    }
}
