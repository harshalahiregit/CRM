<?php

namespace App\Services\Vendor;

use App\Models\Tpv\HsseIncident;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvWorker;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Models\Vendor\VendorScorecard;
use App\Support\Vendor\VendorStatus;

/**
 * Vendor Rating System (Doc 5). Synthesises the operational data the rest of the
 * program produces — HSSE incidents, safety strikes, statutory-document currency,
 * and workforce readiness — into a live 0–100 score across three dimensions and
 * an overall letter band. A monthly snapshot (snapshot()) persists the trend.
 *
 * The model (weights, deductions, bands) is config-driven (config/vrs.php) so it
 * can be retuned without code changes.
 */
class VendorScorecardService
{
    /** Live scorecard for a vendor — computed from current data, never stored. */
    public function compute(Vendor $vendor): array
    {
        $safety     = $this->safetyScore($vendor);
        $compliance = $this->complianceScore($vendor);
        $workforce  = $this->workforceScore($vendor);

        $w = config('vrs.weights');
        $overall = (int) round(
            $safety['score'] * $w['safety']
            + $compliance['score'] * $w['compliance']
            + $workforce['score'] * $w['workforce']
        );

        return [
            'vendor_id'        => $vendor->id,
            'company_name'     => $vendor->company_name,
            'overall_score'    => $overall,
            'band'             => $this->band($overall),
            'dimensions'       => [
                'safety'     => $safety,
                'compliance' => $compliance,
                'workforce'  => $workforce,
            ],
            'computed_at'      => now()->toIso8601String(),
        ];
    }

    /** Persist this vendor's scorecard for the given period (default this month). */
    public function snapshot(Vendor $vendor, ?string $period = null): VendorScorecard
    {
        $period ??= now()->format('Y-m');
        $card = $this->compute($vendor);

        return VendorScorecard::updateOrCreate(
            ['vendor_id' => $vendor->id, 'period' => $period],
            [
                'tenant_id'        => $vendor->tenant_id,
                'safety_score'     => $card['dimensions']['safety']['score'],
                'compliance_score' => $card['dimensions']['compliance']['score'],
                'workforce_score'  => $card['dimensions']['workforce']['score'],
                'overall_score'    => $card['overall_score'],
                'band'             => $card['band'],
                'breakdown'        => $card['dimensions'],
                'computed_at'      => now(),
            ]
        );
    }

    public function history(Vendor $vendor, int $limit = 12)
    {
        return VendorScorecard::where('vendor_id', $vendor->id)
            ->orderByDesc('period')
            ->limit($limit)
            ->get();
    }

    /* ── Dimensions ─────────────────────────────────────────────────────── */

    private function safetyScore(Vendor $vendor): array
    {
        $ded = config('vrs.safety_deductions');
        $score = 100;

        // Open incidents (not Closed) weigh by severity.
        $incidents = HsseIncident::where('vendor_id', $vendor->id)
            ->where('status', '!=', 'Closed')
            ->get(['severity']);
        $incidentPenalty = $incidents->sum(fn ($i) => $ded['incident'][$i->severity] ?? 0);

        // Active (un-voided) strikes across the vendor's workers.
        $activeStrikes = TpvSafetyStrike::whereNull('voided_at')
            ->whereIn('tpv_worker_id', TpvWorker::where('vendor_id', $vendor->id)->select('id'))
            ->count();
        $strikePenalty = $activeStrikes * $ded['strike'];

        $score = max(0, $score - $incidentPenalty - $strikePenalty);

        return [
            'score'          => $score,
            'open_incidents' => $incidents->count(),
            'active_strikes' => $activeStrikes,
        ];
    }

    private function complianceScore(Vendor $vendor): array
    {
        // A suspended vendor is non-compliant by definition.
        if (in_array($vendor->status, [VendorStatus::SUSPENDED, VendorStatus::BLACKLISTED], true)) {
            return ['score' => 0, 'required' => 0, 'valid' => 0, 'note' => 'Vendor '.strtolower($vendor->status)];
        }

        $required = VendorDocument::requiredFor($vendor->vendor_type ?? 'standard');
        if (empty($required)) {
            return ['score' => 100, 'required' => 0, 'valid' => 0];
        }

        $docs = $vendor->documents()->where('status', 'Approved')->get(['type', 'expires_at']);
        $today = now();
        $valid = 0;
        foreach ($required as $type) {
            $ok = $docs->first(fn ($d) => $d->type === $type
                && (! $d->expires_at || $d->expires_at->gte($today)));
            if ($ok) {
                $valid++;
            }
        }

        return [
            'score'    => (int) round(($valid / count($required)) * 100),
            'required' => count($required),
            'valid'    => $valid,
        ];
    }

    private function workforceScore(Vendor $vendor): array
    {
        $total = TpvWorker::where('vendor_id', $vendor->id)->count();
        if ($total === 0) {
            return ['score' => 100, 'total' => 0, 'active' => 0, 'note' => 'No workforce registered'];
        }

        $active = TpvWorker::where('vendor_id', $vendor->id)
            ->where('status', \App\Support\Tpv\TpvWorkerStatus::ACTIVE)
            ->count();

        return [
            'score'  => (int) round(($active / $total) * 100),
            'total'  => $total,
            'active' => $active,
        ];
    }

    private function band(int $overall): string
    {
        $b = config('vrs.bands');
        if ($overall >= $b['A']) {
            return 'A';
        }
        if ($overall >= $b['B']) {
            return 'B';
        }
        if ($overall >= $b['C']) {
            return 'C';
        }

        return 'D';
    }
}
