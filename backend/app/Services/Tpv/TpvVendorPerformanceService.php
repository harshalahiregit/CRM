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

    /** Dimension keys in display order. The last seven are the §27 dimensions
     *  layered on at weight 0 by default (surfaced, tenant-weightable). */
    public const DIMENSIONS = [
        'safety', 'compliance', 'workforce', 'quality', 'capa', 'conduct', 'inspection', 'documentation',
        'productivity', 'timeliness', 'training', 'environmental', 'security', 'incident', 'meeting_action',
    ];

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
            // §27 dimensions.
            'productivity'   => $this->structuralDim('Productivity', 'No productivity feed yet'),
            'timeliness'     => $this->structuralDim('Timeliness', 'No schedule feed yet'),
            'training'       => $this->trainingDim($vendor),
            'environmental'  => $this->environmentalDim($vendor),
            'security'       => $this->securityDim($vendor, $ded),
            'incident'       => $this->incidentDim($vendor, $ded),
            'meeting_action' => $this->meetingActionDim($vendor, $ded),
        ];

        $w = $vpi['weights'];
        $overall = 0.0;
        foreach ($w as $k => $weight) {
            $overall += ($dims[$k]['score'] ?? 0) * $weight;
        }
        $overall = (int) round($overall);
        $band = $this->band($overall, $vpi['bands']);

        return [
            'vendor_id'     => $vendor->id,
            'company_name'  => $vendor->company_name,
            'vendor_code'   => $vendor->vendor_code,
            'overall_score' => $overall,
            'band'          => $band,
            'band_label'    => config('vpi.band_labels.'.$band, $band),
            'vrs_band'      => $vrs['band'] ?? null,
            'dimensions'    => $dims,
            'weights'       => $w,
            'computed_at'   => now()->toIso8601String(),
        ];
    }

    /**
     * §27 — persist a point-in-time snapshot of a vendor's index so performance
     * history can be tracked across projects, rather than only ever recomputed live.
     */
    public function snapshot(Vendor $vendor, ?string $project = null): \App\Models\Tpv\TpvVendorPerformanceSnapshot
    {
        $c = $this->compute($vendor);

        return \App\Models\Tpv\TpvVendorPerformanceSnapshot::create([
            'tenant_id'     => $vendor->tenant_id,
            'vendor_id'     => $vendor->id,
            'project'       => $project,
            'overall_score' => $c['overall_score'],
            'band'          => $c['band'],
            'dimensions'    => array_map(fn ($d) => $d['score'], $c['dimensions']),
            'captured_at'   => now(),
        ]);
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

    /** A dimension the doc lists but for which no data feed exists yet — surfaced
     *  at a neutral 100 with an explicit note, so the structure is present (§27). */
    private function structuralDim(string $label, string $note): array
    {
        return ['score' => 100, 'label' => $label, 'detail' => ['note' => $note]];
    }

    /** Training — share of this vendor's training records that are passed & valid. */
    private function trainingDim(Vendor $vendor): array
    {
        $rows = \App\Models\Tpv\TpvWorkerTraining::whereIn(
            'tpv_worker_id', TpvWorker::where('vendor_id', $vendor->id)->select('id')
        )->get(['passed', 'valid_until']);

        if ($rows->isEmpty()) {
            return ['score' => 100, 'label' => 'Training', 'detail' => ['records' => 0]];
        }
        $ok = $rows->filter(fn ($r) => $r->passed && (! $r->valid_until || ! $r->valid_until->isPast()))->count();
        $score = (int) round($ok / $rows->count() * 100);

        return ['score' => $score, 'label' => 'Training', 'detail' => ['records' => $rows->count(), 'valid' => $ok]];
    }

    /** Environmental — driven by environmental-category compliance where tracked. */
    private function environmentalDim(Vendor $vendor): array
    {
        $rows = TpvVendorCompliance::where('vendor_id', $vendor->id)
            ->whereIn('category', ['Environment', 'Environmental_Requirements', 'Waste', 'Pollution', 'Chemicals'])
            ->get(['status', 'valid_until']);
        if ($rows->isEmpty()) {
            return ['score' => 100, 'label' => 'Environmental', 'detail' => ['tracked' => 0]];
        }
        $ok  = $rows->filter(fn ($r) => in_array($r->effective_status, ComplianceCatalog::OK_STATUSES, true))->count();
        $pct = (int) round($ok / $rows->count() * 100);

        return ['score' => $pct, 'label' => 'Environmental', 'detail' => ['tracked' => $rows->count(), 'ok' => $ok]];
    }

    /** Security — HSSE incidents of type Security count against the vendor. */
    private function securityDim(Vendor $vendor, array $ded): array
    {
        $count = \App\Models\Tpv\HsseIncident::where('vendor_id', $vendor->id)
            ->where('type', 'Security')->count();
        $score = max(0, 100 - $count * ($ded['security'] ?? 12));

        return ['score' => $score, 'label' => 'Security', 'detail' => ['incidents' => $count]];
    }

    /** Incident — all HSSE incidents, with grave events costing more. */
    private function incidentDim(Vendor $vendor, array $ded): array
    {
        $rows  = \App\Models\Tpv\HsseIncident::where('vendor_id', $vendor->id)->get(['severity']);
        $grave = $rows->filter(fn ($r) => in_array($r->severity, \App\Models\Tpv\HsseIncident::SUSPENDING_SEVERITIES, true))->count();
        $minor = $rows->count() - $grave;
        $score = max(0, 100 - $minor * ($ded['incident'] ?? 10) - $grave * ($ded['incident_grave'] ?? 20));

        return ['score' => $score, 'label' => 'Incident', 'detail' => ['total' => $rows->count(), 'grave' => $grave]];
    }

    /**
     * Meeting-action closure — the doc lists this VPI dimension, but MOM actions
     * live in the shared Meetings module and carry no direct vendor link (they
     * resolve a vendor only through the meeting's subject). Rather than couple VPI
     * into another team's module, the dimension is surfaced structurally; a future
     * vendor-scoped MOM-action feed can supply the real score.
     */
    private function meetingActionDim(Vendor $vendor, array $ded): array
    {
        return $this->structuralDim('Meeting action closure', 'No vendor-scoped MOM-action feed yet');
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
