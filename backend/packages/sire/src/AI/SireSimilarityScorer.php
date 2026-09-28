<?php

namespace Sire\AI;

use Carbon\CarbonImmutable;

/**
 * SIRE — how alike are two issues, and why.
 *
 * Direct port of tests/reference/similarity.mjs; both run
 * fixtures/similarity-cases.json.
 *
 * The output that matters is not the number. It is `signals`: which terms matched,
 * which structural fields agreed, which of the shared terms are rare. A human
 * confirms or dismisses a duplicate by reading those. "92% similar" is a claim; a
 * list of seven shared terms and the same screen is evidence.
 */
class SireSimilarityScorer
{
    public const WEIGHT_TITLE      = 0.50;
    public const WEIGHT_BODY       = 0.20;
    public const WEIGHT_STRUCTURAL = 0.30;

    /** Field weights within the structural component. */
    private const STRUCTURAL_WEIGHTS = ['module' => 0.40, 'section' => 0.25, 'screen' => 0.25, 'category' => 0.10];

    public const VERY_LIKELY = 0.85;
    public const LIKELY      = 0.60;
    public const POSSIBLE    = 0.35;

    public function __construct(private readonly SireTextAnalyzer $text)
    {
    }

    public function label(float $score): string
    {
        return match (true) {
            $score >= self::VERY_LIKELY => 'very_likely',
            $score >= self::LIKELY      => 'likely',
            $score >= self::POSSIBLE    => 'possible',
            default                     => 'below_threshold',
        };
    }

    /**
     * @param  array  $query      title, description, module, section, screen, category
     * @param  array  $candidate  same, plus is_duplicate and closed_at
     * @param  array  $opts       rare_tokens (array<string>), now (CarbonImmutable)
     */
    public function score(array $query, array $candidate, array $opts = []): array
    {
        $qTitle = $this->text->tokenize($query['title'] ?? null);
        $cTitle = $this->text->tokenize($candidate['title'] ?? null);
        $qBody  = $this->text->tokenize($query['description'] ?? null);
        $cBody  = $this->text->tokenize($candidate['description'] ?? null);

        $titleSim = $this->text->jaccard($qTitle, $cTitle);
        $bodySim  = $this->text->jaccard($qBody, $cBody);
        [$structural, $matched] = $this->structural($query, $candidate);

        $score = self::WEIGHT_TITLE * $titleSim
            + self::WEIGHT_BODY * $bodySim
            + self::WEIGHT_STRUCTURAL * $structural;

        $shared = $this->text->shared(array_merge($qTitle, $qBody), array_merge($cTitle, $cBody));

        // A term appearing in a handful of issues carries far more evidence than
        // one appearing in hundreds. Bounded: a single rare word is a hint, not a
        // verdict.
        $rare = array_values(array_intersect($shared, $opts['rare_tokens'] ?? []));
        if ($rare !== []) {
            $score += 0.10;
        }

        // A candidate that is itself a duplicate points somewhere else. Offering
        // it sends the reader down a chain instead of to the real issue.
        if (! empty($candidate['is_duplicate'])) {
            $score *= 0.80;
        }

        // Age is weak evidence against, not proof. A year-old issue with the same
        // symptom is more often a recurrence than a duplicate — and SIRE has a
        // recurrence register for exactly that.
        if (! empty($candidate['closed_at']) && isset($opts['now'])) {
            $closed = CarbonImmutable::parse($candidate['closed_at']);
            if ($closed->diffInDays(CarbonImmutable::instance($opts['now']), false) > 365) {
                $score *= 0.90;
            }
        }

        $score = round(max(0.0, min(1.0, $score)), 4);

        return [
            'score'   => $score,
            'label'   => $this->label($score),
            'signals' => [
                'title_similarity'   => round($titleSim, 4),
                'body_similarity'    => round($bodySim, 4),
                'structural_score'   => round($structural, 4),
                'structural_matched' => $matched,
                'shared_terms'       => array_slice($shared, 0, 12),
                'rare_terms'         => array_slice($rare, 0, 6),
            ],
        ];
    }

    /**
     * A field missing on either side scores zero rather than being excluded from
     * the average.
     *
     * Missing context SHOULD lower confidence: an issue with no captured screen
     * genuinely is harder to match, and renormalising the weights would inflate
     * the score exactly where the evidence is thinnest.
     *
     * @return array{0: float, 1: array<int, string>}
     */
    private function structural(array $a, array $b): array
    {
        $score = 0.0;
        $matched = [];

        foreach (self::STRUCTURAL_WEIGHTS as $field => $weight) {
            $left = $a[$field] ?? null;
            $right = $b[$field] ?? null;

            if ($left !== null && $left !== '' && $left === $right) {
                $score += $weight;
                $matched[] = $field;
            }
        }

        return [$score, $matched];
    }
}
