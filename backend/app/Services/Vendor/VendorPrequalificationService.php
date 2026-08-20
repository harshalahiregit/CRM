<?php

namespace App\Services\Vendor;

use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\QualificationStatus;

/**
 * Vendor Prequalification (gap report area 6).
 *
 * Scores a sectioned questionnaire (config/vendor_prequalification.php) to a
 * normalised 0–100 and bands it to Qualified / Conditional / Not Qualified.
 * Higher is better — the opposite polarity to risk classification.
 */
class VendorPrequalificationService
{
    /** The questionnaire catalogue (sections → questions → options). */
    public function catalogue(): array
    {
        return config('vendor_prequalification.sections', []);
    }

    /**
     * Score a set of answers.
     *
     * @param  array<string,string>  $answers  question key => chosen option value
     * @return array{score:int, status:string, sections:array}
     */
    public function compute(array $answers): array
    {
        $sections  = config('vendor_prequalification.sections', []);
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
            'status'   => QualificationStatus::fromScore($score),
            'sections' => $breakdown,
        ];
    }

    /**
     * Assess a vendor and persist the outcome. Unknown question keys and invalid
     * option values are dropped so a stale form can never poison the score.
     */
    public function assess(Vendor $vendor, array $answers, ?string $notes, User $actor): Vendor
    {
        $clean = [];
        foreach (config('vendor_prequalification.sections', []) as $section) {
            foreach ($section['questions'] ?? [] as $qKey => $q) {
                $val = $answers[$qKey] ?? null;
                if ($val !== null && isset($q['options'][$val])) {
                    $clean[$qKey] = $val;
                }
            }
        }

        $c = $this->compute($clean);

        $vendor->update([
            'qualification_status'      => $c['status'],
            'qualification_score'       => $c['score'],
            'qualification_responses'   => $clean,
            'qualification_notes'       => $notes !== null && trim($notes) !== '' ? $notes : null,
            'qualification_assessed_at' => now(),
            'qualification_assessed_by' => $actor->id,
        ]);

        $vendor->recordAudit('prequalified', $actor,
            'Prequalification '.QualificationStatus::label($c['status'])." ({$c['score']}/100)");

        return $vendor->fresh();
    }

    /** The full prequalification picture for the panel. */
    public function snapshot(Vendor $vendor): array
    {
        $answers  = $vendor->qualification_responses ?? [];
        $computed = $this->compute($answers);

        return [
            'assessed'    => $vendor->qualification_assessed_at !== null,
            'status'      => $vendor->qualification_status,
            'status_label' => $vendor->qualification_status ? QualificationStatus::label($vendor->qualification_status) : null,
            'score'       => $vendor->qualification_score,
            'passing'     => QualificationStatus::isPassing($vendor->qualification_status),
            'answers'     => $answers,
            'sections'    => $computed['sections'],
            'notes'       => $vendor->qualification_notes,
            'assessed_at' => $vendor->qualification_assessed_at,
            'assessed_by' => optional($vendor->qualificationAssessor)->name,
            'catalogue'   => config('vendor_prequalification.sections', []),
            'outcomes'    => config('vendor_prequalification.outcomes', []),
        ];
    }
}
