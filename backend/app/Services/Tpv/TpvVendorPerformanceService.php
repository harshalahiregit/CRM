<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvCapa;
use App\Models\Tpv\TpvInspection;
use App\Models\Tpv\TpvNcr;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvVendorCompliance;
use App\Models\Tpv\TpvVendorViolation;
use App\Models\Tpv\TpvWorker;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorScorecardService;
use App\Support\Tpv\ComplianceCatalog;

/**
 * Vendor Performance Index (Sangoe TPV §27). An ADDITIVE superset of the VRS
 * scorecard: it reuses the three VRS dimensions (safety, compliance, workforce)
 * verbatim and layers on five governance dimensions the program now produces —
 * quality (NCRs), CAPA closure, conduct (violations + strikes), inspections and
 * documentation currency — into a weighted 0–100 index with an A–E band.
 *
 * The base VRS (VendorScorecardService, config/vrs.php) is never modified; VPI
 * only reads it. Weights and bands live in config/vpi.php.
 */
class TpvVendorPerformanceService
{
    public function __construct(
        private VendorScorecardService $vrs,
        private \App\Support\Tpv\TpvSettings $settings,
    ) {}

    /** Dimension keys in display order. */
    public const DIMENSIONS = ['safety', 'compliance', 'workforce', 'quality', 'capa', 'conduct', 'inspection', 'documentation'];

    /** Live VPI for one vendor. */
    public function compute(Vendor $vendor): array
    {
        $vrs = $this->vrs->compute($vendor);
        // Weights / deductions / bands / window are tenant-configurable (§34);
        // with no override this is exactly config/vpi.php.
        $vpi = $this->settings->vpi($vendor->tenant_id);
        $ded = $vpi['deductions'];

        $dims = [
            'safety'        => $this->fromVrs($vrs, 'safety', 'Safety'),
            'compliance'    => $this->complianceDim($vendor, $vrs),
            'workforce'     => $this->fromVrs($vrs, 'workforce', 'Workforce'),
            'quality'       => $this->ncrDim($vendor, $ded),
            'capa'          => $this->capaDim($vendor, $ded),
            'conduct'       => $this->conductDim($vendor, $ded),
            'inspection'    => $this->inspectionDim($vendor),
            'documentation' => $this->documentationDim($vendor, (int) $vpi['doc_expiring_window_days']),
        ];

        $w = $vpi['weights'];
        $overall = 0.0;
        foreach ($w as $k => $weight) {
            $overall += ($dims[$k]['score'] ?? 0) * $weight;
        }
        $overall = (int) round($overall);

        return [
            'vendor_id'     => $vendor->id,
            'company_name'  => $vendor->company_name,
            'vendor_code'   => $vendor->vendor_code,
            'overall_score' => $overall,
            'band'          => $this->band($overall, $vpi['bands']),
            'vrs_band'      => $vrs['band'] ?? null,
            'dimensions'    => $dims,
            'weights'       => $w,
            'computed_at'   => now()->toIso8601String(),
        ];
    }

    /** VPI leaderboard across all tenant vendors — worst index first. */
    public function roster(int $tenantId): array
    {
        $rows = Vendor::forTenant($tenantId)->get()
            ->map(function (Vendor $v) {
                $c = $this->compute($v);

                return [
                    'vendor_id'     => $v->id,
                    'vendor'        => $v->company_name,
                    'vendor_code'   => $v->vendor_code,
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

    private function fromVrs(array $vrs, string $key, string $label): array
    {
        $d = $vrs['dimensions'][$key] ?? ['score' => 100];

        return ['score' => (int) ($d['score'] ?? 100), 'label' => $label, 'detail' => $d];
    }

    /**
     * Prefer the categorised compliance register % (Rule 8) when the vendor is
     * tracked there; otherwise fall back to the VRS statutory-document score.
     */
    private function complianceDim(Vendor $vendor, array $vrs): array
    {
        $rows = TpvVendorCompliance::where('vendor_id', $vendor->id)->get(['status', 'valid_until']);
        if ($rows->isEmpty()) {
            return $this->fromVrs($vrs, 'compliance', 'Compliance');
        }
        $ok = $rows->filter(fn ($r) => in_array($r->effective_status, ComplianceCatalog::OK_STATUSES, true))->count();
        $pct = (int) round($ok / $rows->count() * 100);

        return ['score' => $pct, 'label' => 'Compliance', 'detail' => ['tracked' => $rows->count(), 'ok' => $ok]];
    }

    private function ncrDim(Vendor $vendor, array $ded): array
    {
        $open = TpvNcr::where('vendor_id', $vendor->id)->where('status', '!=', 'Closed');
        $openCount = (clone $open)->count();
        $overdue = (clone $open)->whereNotNull('due_date')->whereDate('due_date', '<', now())->count();
        $score = max(0, 100 - $openCount * $ded['ncr_open'] - $overdue * $ded['ncr_overdue']);

        return ['score' => $score, 'label' => 'Quality (NCR)', 'detail' => ['open' => $openCount, 'overdue' => $overdue]];
    }

    private function capaDim(Vendor $vendor, array $ded): array
    {
        $open = TpvCapa::where('vendor_id', $vendor->id)->where('status', '!=', 'Verified');
        $openCount = (clone $open)->count();
        $overdue = (clone $open)->whereNotNull('due_date')->whereDate('due_date', '<', now())->count();
        $score = max(0, 100 - $openCount * $ded['capa_open'] - $overdue * $ded['capa_overdue']);

        return ['score' => $score, 'label' => 'CAPA closure', 'detail' => ['open' => $openCount, 'overdue' => $overdue]];
    }

    private function conductDim(Vendor $vendor, array $ded): array
    {
        $points = (int) TpvVendorViolation::where('vendor_id', $vendor->id)->where('status', 'Open')->sum('points');
        $strikes = TpvSafetyStrike::whereNull('voided_at')
            ->whereIn('tpv_worker_id', TpvWorker::where('vendor_id', $vendor->id)->select('id'))
            ->count();
        $score = max(0, 100 - $points * $ded['violation_point'] - $strikes * $ded['strike']);

        return ['score' => $score, 'label' => 'Conduct', 'detail' => ['violation_points' => $points, 'strikes' => $strikes]];
    }

    private function inspectionDim(Vendor $vendor): array
    {
        $scores = TpvInspection::where('vendor_id', $vendor->id)
            ->whereIn('status', ['Completed', 'Closed'])
            ->whereNotNull('score')
            ->pluck('score');
        if ($scores->isEmpty()) {
            return ['score' => 100, 'label' => 'Inspection', 'detail' => ['conducted' => 0, 'note' => 'No conducted inspections']];
        }

        return ['score' => (int) round($scores->avg()), 'label' => 'Inspection', 'detail' => ['conducted' => $scores->count(), 'avg' => round($scores->avg(), 1)]];
    }

    private function documentationDim(Vendor $vendor, int $window): array
    {
        $docs = $vendor->documents()->get(['status', 'expires_at']);
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
        // Expired docs cost a full slot; expiring ones cost half.
        $score = (int) round(max(0, ($total - $expired - $expiring * 0.5) / $total) * 100);

        return ['score' => $score, 'label' => 'Documentation', 'detail' => ['docs' => $total, 'expired' => $expired, 'expiring' => $expiring]];
    }

    private function band(int $overall, array $b): string
    {
        foreach (['A', 'B', 'C', 'D'] as $letter) {
            if ($overall >= $b[$letter]) {
                return $letter;
            }
        }

        return 'E';
    }
}
