<?php

namespace Sire\AI;

use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;

/**
 * SIRE — recommend issue type, module, severity and priority.
 *
 * Direct port of tests/reference/classification.mjs; both run
 * fixtures/classification-cases.json.
 *
 * WHAT THIS IS, PLAINLY: a weighted k-nearest-neighbour vote over the tenant's own
 * historical issues. Not a language model, and it does not pretend to be. It runs
 * through the AI foundation because it produces exactly what that foundation
 * exists for — a suggestion with a confidence, an explanation and a recorded human
 * decision — and because it satisfies the hardest requirements for free:
 *
 *   - nothing leaves the tenant, so requirement 10 is trivially met
 *   - the reason is checkable: "7 of 9 similar issues were logged as Bug", against
 *     a list of neighbours the user can click
 *   - it costs nothing per call and cannot be unavailable
 *
 * A real model can be added later as another provider. This is the baseline it
 * would have to beat, and a baseline that explains itself is a high bar.
 */
class SireClassifier
{
    /** Below this many neighbours, the honest answer is "I don't know". */
    private const MIN_NEIGHBOURS = 2;

    /** Coverage saturates here: five agreeing neighbours is as sure as this gets. */
    private const FULL_COVERAGE = 5;

    /** Below this, a recommendation is noise and is withheld. */
    private const MIN_CONFIDENCE = 0.25;

    /** Neighbours consulted. */
    private const K = 15;

    public function __construct(private readonly SireDuplicateDetector $detector)
    {
    }

    public function classify(Report $report, SireUserIdentity $viewer): array
    {
        // The same retrieval that finds duplicates finds neighbours. One index,
        // one scorer, two questions.
        $found = $this->detector->detect($report, $viewer);

        $neighbours = collect($found['candidates'])
            ->take(self::K)
            ->map(fn (array $c) => [
                'weight'   => (float) $c['score'],
                'ref'      => $c['candidate']['report_number'],
                'module'   => $c['candidate']['module'],
                'category' => $c['candidate']['category'],
                'severity' => $c['candidate']['severity'],
                'priority' => $c['candidate']['priority'] ?? null,
            ])
            ->all();

        /*
         * The captured module is an OBSERVATION, not an inference. Report Issue
         * records the screen the person was actually on; guessing the module from
         * wording when the browser already told us would be worse in every case.
         */
        $captured = $report->module;

        $module = $captured
            ? [
                'value' => $captured, 'confidence' => 0.95, 'abstained' => false,
                'reason' => 'Taken from the screen you were on when you reported this.',
                'tally' => [], 'source' => 'captured',
            ]
            : $this->vote($neighbours, 'module') + ['source' => 'neighbours'];

        return [
            'neighbour_count' => count($neighbours),
            'neighbours'      => array_slice(array_column($neighbours, 'ref'), 0, 5),
            'module'          => $module,
            'category'        => $this->vote($neighbours, 'category') + ['source' => 'neighbours'],
            'severity'        => $this->vote($neighbours, 'severity') + ['source' => 'neighbours'],
            'priority'        => $this->vote($neighbours, 'priority') + ['source' => 'neighbours'],
        ];
    }

    /**
     * Weighted vote over one field.
     *
     * Confidence is (winning share) × (coverage). Two neighbours in perfect
     * agreement therefore do NOT report 100% — unanimity among a tiny sample is
     * the most common way a recommender lies, and the coverage term is what stops
     * it.
     */
    public function vote(array $neighbours, string $field): array
    {
        $usable = array_values(array_filter(
            $neighbours,
            fn (array $n) => isset($n[$field]) && $n[$field] !== null && $n[$field] !== '',
        ));

        if (count($usable) < self::MIN_NEIGHBOURS) {
            return [
                'value' => null, 'confidence' => 0.0, 'abstained' => true, 'tally' => [],
                'reason' => count($usable) === 0
                    ? 'No similar issues have been classified yet.'
                    : 'Only '.count($usable).' similar issue was found — not enough to suggest anything.',
            ];
        }

        $weights = [];
        $total = 0.0;

        foreach ($usable as $n) {
            $w = max(0.0, (float) ($n['weight'] ?? 0));
            $weights[$n[$field]] = ($weights[$n[$field]] ?? 0) + $w;
            $total += $w;
        }

        if ($total <= 0) {
            return ['value' => null, 'confidence' => 0.0, 'abstained' => true, 'tally' => [],
                    'reason' => 'Similar issues carried no weight.'];
        }

        arsort($weights);

        $tally = [];
        foreach ($weights as $value => $weight) {
            $tally[] = ['value' => $value, 'weight' => round($weight, 4), 'share' => round($weight / $total, 4)];
        }

        $winner = $tally[0];
        $coverage = min(1.0, count($usable) / self::FULL_COVERAGE);
        $confidence = round($winner['share'] * $coverage, 4);

        if ($confidence < self::MIN_CONFIDENCE) {
            return ['value' => null, 'confidence' => $confidence, 'abstained' => true,
                    'tally' => array_slice($tally, 0, 4),
                    'reason' => 'Similar issues disagree too much to suggest one confidently.'];
        }

        $agreeing = count(array_filter($usable, fn (array $n) => $n[$field] === $winner['value']));

        return [
            'value'      => $winner['value'],
            'confidence' => $confidence,
            'abstained'  => false,
            'tally'      => array_slice($tally, 0, 4),
            // Checkable against the neighbour list the user is shown.
            'reason'     => sprintf(
                '%d of %d similar issues were %s %s.',
                $agreeing, count($usable),
                $field === 'category' ? 'logged as' : 'set to',
                $winner['value'],
            ),
        ];
    }

    /** The overall confidence a suggestion record carries: the mean of what it offered. */
    public function overallConfidence(array $result): ?float
    {
        $offered = array_filter(
            array_map(fn (string $f) => $result[$f], ['module', 'category', 'severity', 'priority']),
            fn (array $r) => ! $r['abstained'],
        );

        if ($offered === []) {
            return null;
        }

        return round(array_sum(array_column($offered, 'confidence')) / count($offered), 4);
    }
}
