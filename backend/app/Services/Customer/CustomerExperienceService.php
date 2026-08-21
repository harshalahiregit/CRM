<?php

namespace App\Services\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientFeedback;
use Illuminate\Support\Collection;

/**
 * §10 — Customer Experience.
 *
 * CSAT and NPS are not two versions of the same number and must not be averaged
 * together. CSAT is the mean of per-interaction ratings on a five-point scale.
 * NPS is the standard net score: the share of promoters (9-10) minus the share
 * of detractors (0-6), which runs from -100 to +100 and is not a percentage of
 * anything.
 *
 * Both are also reported normalised to 0-100 so Customer Health (§8) has one
 * comparable figure to score without having to know either scale.
 */
class CustomerExperienceService
{
    /** Below this, a score says more about the sample than the customer. */
    public const MIN_RESPONSES = 2;

    public function forClient(Client $client): array
    {
        return $this->summarise(
            ClientFeedback::forTenant($client->tenant_id)->where('client_id', $client->id)->get()
        );
    }

    public function summarise(Collection $rows): array
    {
        $csat = $rows->where('metric', ClientFeedback::CSAT);
        $nps  = $rows->where('metric', ClientFeedback::NPS);

        return [
            'csat'          => $this->csat($csat),
            'nps'           => $this->nps($nps),
            'responses'     => $rows->count(),
            'last_response' => $rows->max('responded_at')?->toIso8601String(),
        ];
    }

    private function csat(Collection $rows): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $avg = round($rows->avg('score'), 2);

        return [
            'average'    => $avg,           // out of 5
            'out_of'     => ClientFeedback::MAX[ClientFeedback::CSAT],
            'percent'    => round(($avg / ClientFeedback::MAX[ClientFeedback::CSAT]) * 100, 1),
            // The industry reading of CSAT: the share who answered 4 or 5.
            'satisfied'  => round(($rows->where('score', '>=', 4)->count() / $rows->count()) * 100, 1),
            'responses'  => $rows->count(),
            'provisional' => $rows->count() < self::MIN_RESPONSES,
        ];
    }

    private function nps(Collection $rows): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $total     = $rows->count();
        $promoters = $rows->where('score', '>=', 9)->count();
        $passives  = $rows->whereBetween('score', [7, 8])->count();
        $detractors = $total - $promoters - $passives;

        $score = round((($promoters - $detractors) / $total) * 100);

        return [
            'score'      => $score,          // -100 … +100
            'promoters'  => $promoters,
            'passives'   => $passives,
            'detractors' => $detractors,
            'responses'  => $total,
            // NPS maps to 0-100 by shifting the midpoint, NOT by clamping:
            // a -100 and a 0 are genuinely different and must not both read 0.
            'normalised' => round(($score + 100) / 2, 1),
            'band'       => match (true) {
                $score >= 50 => 'Excellent',
                $score >= 0  => 'Good',
                $score >= -50 => 'Poor',
                default      => 'Critical',
            },
            'provisional' => $total < self::MIN_RESPONSES,
        ];
    }

    /**
     * One 0-100 figure for Customer Health, or null when nobody has answered.
     *
     * When both metrics exist they are weighted equally. NPS is a relationship
     * measure and CSAT a service one, and the document lists "Customer feedback"
     * as a single Health parameter, so collapsing them here keeps that promise
     * without Health needing to know either scale.
     */
    public function healthSignal(Client $client): ?array
    {
        $summary = $this->forClient($client);
        $parts   = [];

        if ($summary['csat']) {
            $parts['CSAT'] = $summary['csat']['percent'];
        }
        if ($summary['nps']) {
            $parts['NPS'] = $summary['nps']['normalised'];
        }

        if ($parts === []) {
            return null;
        }

        $detail = collect($parts)
            ->map(fn ($v, $k) => $k === 'NPS'
                ? "NPS {$summary['nps']['score']}"
                : "CSAT {$summary['csat']['average']}/5")
            ->implode(' · ');

        return [
            'score'  => round(array_sum($parts) / count($parts), 1),
            'detail' => $detail." across {$summary['responses']} responses",
        ];
    }
}
