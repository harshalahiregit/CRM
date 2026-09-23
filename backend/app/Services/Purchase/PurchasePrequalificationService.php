<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Support\Purchase\PurchaseQualificationStatus;

/**
 * Purchase vendor Prequalification — the Purchase-side mirror of
 * App\Services\Vendor\VendorPrequalificationService.
 *
 * Scores a sectioned questionnaire (config/purchase_prequalification.php) to a
 * normalised 0–100 and bands it to Qualified / Conditional / Not Qualified.
 * Higher is better — the opposite polarity to the lean admin-set Risk Score.
 */
class PurchasePrequalificationService
{
    /**
     * The questionnaire catalogue (sections → questions → options).
     *
     * A tenant's own if it has edited one, otherwise the shipped default. It
     * used to read the config file directly, which is why there was no way to
     * change a question without a deploy.
     */
    public function catalogue(int $tenantId): array
    {
        return \App\Support\Purchase\PrequalificationCatalogue::forTenant($tenantId);
    }

    /**
     * Score a set of answers.
     *
     * Scored against THIS TENANT's questionnaire — a workspace that removed a
     * question must not keep being scored out of a maximum that includes it.
     *
     * @param  array<string,string>  $answers  question key => chosen option value
     * @return array{score:int, status:string, sections:array}
     */
    public function compute(array $answers, int $tenantId): array
    {
        $sections  = $this->catalogue($tenantId);
        $sum       = 0;
        $max       = 0;
        $breakdown = [];

        foreach ($sections as $sKey => $section) {
            $sSum = 0;
            $sMax = 0;
            $rows = [];

            foreach ($section['questions'] ?? [] as $qKey => $q) {
                $options = $q['options'] ?? [];
                $qMax    = $options ? max(array_map(fn ($o) => $o['points'], $options)) : 0;
                $chosen  = $answers[$qKey] ?? null;
                $pts     = ($chosen !== null && isset($options[$chosen])) ? $options[$chosen]['points'] : 0;

                $sSum += $pts;
                $sMax += $qMax;
                $rows[] = [
                    'key'          => $qKey,
                    'label'        => $q['label'] ?? $qKey,
                    'value'        => $chosen,
                    'option_label' => $options[$chosen]['label'] ?? null,
                    'points'       => $pts,
                    'max'          => $qMax,
                ];
            }

            $sum += $sSum;
            $max += $sMax;
            $breakdown[] = [
                'key'       => $sKey,
                'label'     => $section['label'] ?? $sKey,
                'points'    => $sSum,
                'max'       => $sMax,
                'percent'   => $sMax > 0 ? (int) round($sSum / $sMax * 100) : 0,
                'questions' => $rows,
            ];
        }

        $score = $max > 0 ? (int) round($sum / $max * 100) : 0;

        return [
            'score'    => $score,
            'status'   => PurchaseQualificationStatus::fromScore($score),
            'sections' => $breakdown,
        ];
    }

    /**
     * Assess a Purchase vendor and persist the outcome. Unknown question keys and
     * invalid option values are dropped so a stale form can never poison the score.
     */
    public function assess(PurchaseVendor $vendor, array $answers, ?string $notes, User $actor): PurchaseVendor
    {
        $clean = [];
        // The tenant's own questionnaire, so an answer to a question they
        // removed is dropped rather than scored.
        foreach ($this->catalogue((int) $vendor->tenant_id) as $section) {
            foreach ($section['questions'] ?? [] as $qKey => $q) {
                $val = $answers[$qKey] ?? null;
                if ($val !== null && isset($q['options'][$val])) {
                    $clean[$qKey] = $val;
                }
            }
        }

        $c = $this->compute($clean, (int) $vendor->tenant_id);

        $vendor->update([
            'qualification_status'    => $c['status'],
            'qualification_score'     => $c['score'],
            'qualification_responses' => $clean,
            'qualification_notes'     => $notes !== null && trim($notes) !== '' ? $notes : null,
            'qualified_at'            => now(),
            'qualified_by'            => $actor->id,
        ]);

        $vendor->recordAudit('prequalified', $actor,
            'Prequalification '.PurchaseQualificationStatus::label($c['status'])." ({$c['score']}/100)");

        return $vendor->fresh();
    }

    /** The full prequalification picture for the panel. */
    public function snapshot(PurchaseVendor $vendor): array
    {
        $answers  = $vendor->qualification_responses ?? [];
        $computed = $this->compute($answers, (int) $vendor->tenant_id);

        return [
            'assessed'     => $vendor->qualified_at !== null,
            'status'       => $vendor->qualification_status,
            'status_label' => $vendor->qualification_status ? PurchaseQualificationStatus::label($vendor->qualification_status) : null,
            'score'        => $vendor->qualification_score,
            'passing'      => PurchaseQualificationStatus::isPassing($vendor->qualification_status),
            'answers'      => $answers,
            'sections'     => $computed['sections'],
            'notes'        => $vendor->qualification_notes,
            'assessed_at'  => $vendor->qualified_at,
            'assessed_by'  => optional($vendor->qualificationAssessor)->name,
            'catalogue'    => $this->catalogue((int) $vendor->tenant_id),
            'outcomes'     => config('purchase_prequalification.outcomes', []),
        ];
    }
}
