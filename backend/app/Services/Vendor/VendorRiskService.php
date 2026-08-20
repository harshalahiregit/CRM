<?php

namespace App\Services\Vendor;

use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\RiskTier;

/**
 * Vendor Risk Classification (gap report area 2).
 *
 * Turns a set of answered risk factors into a normalised 0–100 score and a tier
 * (Critical/High/Medium/Low), stored on the vendor. Distinct from the VRS
 * performance scorecard — this is forward-looking risk, not past performance.
 */
class VendorRiskService
{
    /** The factor catalogue for the assessment form. */
    public function catalogue(): array
    {
        return config('vendor_risk.factors', []);
    }

    /**
     * Score a set of selected options.
     *
     * @param  array<string,string>  $selected  factor key => chosen option value
     * @return array{score:int, tier:string, breakdown:array, monitoring:?string}
     */
    public function compute(array $selected): array
    {
        $factors   = config('vendor_risk.factors', []);
        $sum       = 0;
        $max       = 0;
        $breakdown = [];

        foreach ($factors as $key => $def) {
            $options = $def['options'] ?? [];
            $maxPts  = $options ? max(array_map(fn ($o) => $o['points'], $options)) : 0;
            $max    += $maxPts;

            $chosen = $selected[$key] ?? null;
            $pts    = ($chosen !== null && isset($options[$chosen])) ? $options[$chosen]['points'] : 0;
            $sum   += $pts;

            $breakdown[] = [
                'key'          => $key,
                'label'        => $def['label'] ?? $key,
                'value'        => $chosen,
                'option_label' => $options[$chosen]['label'] ?? null,
                'points'       => $pts,
                'max'          => $maxPts,
            ];
        }

        $score = $max > 0 ? (int) round($sum / $max * 100) : 0;
        $tier  = $this->tierFromScore($score);

        return [
            'score'      => $score,
            'tier'       => $tier,
            'breakdown'  => $breakdown,
            'monitoring' => RiskTier::monitoringDepth($tier),
        ];
    }

    /** Band a normalised score to the highest tier whose lower bound it meets. */
    public function tierFromScore(int $score): string
    {
        $tiers = config('vendor_risk.tiers', []);
        arsort($tiers);   // highest threshold first

        foreach ($tiers as $tier => $min) {
            if ($score >= $min) {
                return $tier;
            }
        }

        return RiskTier::LOW;
    }

    /**
     * Assess a vendor and persist the classification. Unknown factor keys and
     * invalid option values are dropped, so a stale form can never poison the
     * stored answers or the score.
     */
    public function assess(Vendor $vendor, array $selected, ?string $notes, User $actor): Vendor
    {
        $factors = config('vendor_risk.factors', []);
        $clean   = [];

        foreach ($factors as $key => $def) {
            $val = $selected[$key] ?? null;
            if ($val !== null && isset($def['options'][$val])) {
                $clean[$key] = $val;
            }
        }

        $c = $this->compute($clean);

        $vendor->update([
            'risk_level'       => $c['tier'],
            'risk_score'       => $c['score'],
            'risk_factors'     => $clean,
            'risk_notes'       => $notes !== null && trim($notes) !== '' ? $notes : null,
            'risk_assessed_at' => now(),
            'risk_assessed_by' => $actor->id,
        ]);

        $vendor->recordAudit('risk_assessed', $actor, "Risk classified {$c['tier']} ({$c['score']}/100)");

        return $vendor->fresh();
    }

    /**
     * The full risk picture for a vendor — the stored classification plus the
     * factor catalogue and a breakdown of what was chosen, for the panel.
     */
    public function snapshot(Vendor $vendor): array
    {
        $selected = $vendor->risk_factors ?? [];
        $computed = $this->compute($selected);

        return [
            'assessed'    => $vendor->risk_assessed_at !== null,
            // Stored values are authoritative (they are what the tier gates on);
            // the recomputed breakdown only explains which options were chosen.
            'level'       => $vendor->risk_level,
            'score'       => $vendor->risk_score,
            'monitoring'  => $vendor->risk_level ? RiskTier::monitoringDepth($vendor->risk_level) : null,
            'factors'     => $selected,
            'breakdown'   => $computed['breakdown'],
            'notes'       => $vendor->risk_notes,
            'assessed_at' => $vendor->risk_assessed_at,
            'assessed_by' => optional($vendor->riskAssessor)->name,
            'catalogue'   => config('vendor_risk.factors', []),
            'tiers'       => RiskTier::ALL,
            // tier => minimum normalised score, so the form can preview the tier
            // live as options are picked (mirrors tierFromScore()).
            'bands'       => config('vendor_risk.tiers', []),
        ];
    }
}
