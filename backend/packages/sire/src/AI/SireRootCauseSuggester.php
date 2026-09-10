<?php

namespace Sire\AI;

use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;

/**
 * SIRE — AI SUGGESTED root cause. Never a confirmed one.
 *
 * Direct port of tests/reference/rootCauseSuggestion.mjs.
 *
 * THE RULE THIS CLASS EXISTS TO ENFORCE: nothing here invents a root cause.
 *
 * A root cause is a claim about why something broke. A generator that wrote one
 * from templates would be producing plausible prose with no evidence behind it,
 * and plausible prose is exactly what ends up pasted into a field labelled
 * "Confirmed Root Cause" by someone in a hurry.
 *
 * So the suggestion is GROUNDED. The category is voted from historical issues
 * whose root cause a HUMAN ALREADY CONFIRMED, and the description is QUOTED from
 * the nearest of those, attributed by issue number. The output is never "the cause
 * is X" — it is "SIR-00123, 88% similar, was caused by X".
 *
 * Confirming remains SireRootCauseService::confirm(), performed by a person, with
 * its own capability. This class cannot confirm anything.
 */
class SireRootCauseSuggester
{
    private const MIN_CONFIRMED_NEIGHBOURS = 2;

    /** A factor seen once is that issue's detail; twice is a pattern. */
    private const FACTOR_PATTERN_THRESHOLD = 2;

    public function __construct(
        private readonly SireDuplicateDetector $detector,
        private readonly SireClassifier $classifier,
    ) {
    }

    public function suggest(Report $report, SireUserIdentity $viewer): array
    {
        $found = $this->detector->detect($report, $viewer);

        // Only issues whose analysis a human confirmed count as evidence. An
        // unconfirmed RCA is someone's working note, not a finding.
        $confirmed = collect($found['candidates'])
            ->map(fn (array $c) => [
                'ref'        => $c['candidate']['report_number'],
                'weight'     => (float) $c['score'],
                'root_cause' => $c['candidate']['root_cause'] ?? null,
            ])
            ->filter(fn (array $n) => ! empty($n['root_cause']['confirmed_at']))
            ->values();

        if ($confirmed->count() < self::MIN_CONFIRMED_NEIGHBOURS) {
            return $this->abstain(
                $confirmed->count() === 0
                    ? 'No similar issue has a confirmed root cause yet.'
                    : 'Only one similar issue has a confirmed root cause — not enough to suggest from.',
                $confirmed->pluck('ref')->all(),
            );
        }

        $categoryVote = $this->classifier->vote(
            $confirmed->map(fn (array $n) => ['weight' => $n['weight'], 'category' => $n['root_cause']['category']])->all(),
            'category',
        );

        if ($categoryVote['abstained']) {
            return $this->abstain($categoryVote['reason'], $confirmed->pluck('ref')->all(), $categoryVote['confidence']);
        }

        /*
         * Quote the CLOSEST neighbour that AGREES with the winning category.
         * Quoting the closest overall could attribute a description to a category
         * it does not belong to, which is worse than saying nothing.
         */
        $source = $confirmed
            ->filter(fn (array $n) => $n['root_cause']['category'] === $categoryVote['value'])
            ->sortByDesc('weight')
            ->first();

        return [
            'abstained'            => false,
            'confidence'           => $categoryVote['confidence'],
            'category'             => $categoryVote['value'],
            'reason'               => $categoryVote['reason'],
            // Always attributed. The UI renders this as a quotation, never as a
            // finding, and the label above it says "AI Suggested Root Cause".
            'quoted_from'          => $source['ref'],
            'description'          => $source['root_cause']['description'],
            'detection_gap'        => $source['root_cause']['detection_gap'] ?? null,
            'contributing_factors' => $this->recurringFactors($confirmed),
            'related'              => $confirmed->pluck('ref')->all(),
            'tally'                => $categoryVote['tally'],
        ];
    }

    private function abstain(string $reason, array $related = [], float $confidence = 0.0): array
    {
        return [
            'abstained'            => true,
            'confidence'           => $confidence,
            'category'             => null,
            // An abstention that explains itself tells the investigator this is new
            // territory, rather than leaving them wondering why the panel is blank.
            'reason'               => $reason,
            'quoted_from'          => null,
            'description'          => null,
            'detection_gap'        => null,
            'contributing_factors' => [],
            'related'              => $related,
            'tally'                => [],
        ];
    }

    /** Factors more than one confirmed analysis recorded. */
    private function recurringFactors($confirmed): array
    {
        $counts = [];

        foreach ($confirmed as $n) {
            foreach ($n['root_cause']['contributing_factors'] ?? [] as $factor) {
                $counts[$factor] = ($counts[$factor] ?? 0) + 1;
            }
        }

        $recurring = array_filter($counts, fn (int $c) => $c >= self::FACTOR_PATTERN_THRESHOLD);
        arsort($recurring);

        return array_keys($recurring);
    }
}
