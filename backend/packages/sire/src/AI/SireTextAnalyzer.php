<?php

namespace Sire\AI;

/**
 * SIRE — text to distinctive tokens.
 *
 * Direct port of tests/reference/similarity.mjs; both run
 * fixtures/similarity-cases.json.
 *
 * Punctuation becomes whitespace, so `LeadPolicy::view()` yields `leadpolicy` and
 * `view` — identifiers lifted from a stack trace are exactly the rare terms that
 * make a duplicate obvious to a human, and they are the ones a naive word split
 * would throw away.
 */
class SireTextAnalyzer
{
    private const MIN_TOKEN_LENGTH = 3;

    /** Small and deliberate. Over-aggressive stopwords delete the signal. */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'was', 'were', 'are', 'but',
        'not', 'you', 'your', 'our', 'they', 'them', 'when', 'then', 'than', 'have', 'has',
        'had', 'its', 'it', 'is', 'in', 'on', 'at', 'to', 'of', 'a', 'an', 'be', 'been',
        'can', 'will', 'would', 'should', 'could', 'get', 'got', 'after', 'before', 'into',
        'issue', 'ticket', 'please', 'also', 'user', 'users',
    ];

    /** @return array<int, string> distinct tokens, order not significant */
    public function tokenize(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $raw = preg_split('/\s+/', preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $stopwords = array_flip(self::STOPWORDS);
        $out = [];

        foreach ($raw as $token) {
            if (mb_strlen($token) < self::MIN_TOKEN_LENGTH || isset($stopwords[$token])) {
                continue;
            }
            $normalised = $this->normalise($token);
            if (mb_strlen($normalised) < self::MIN_TOKEN_LENGTH || isset($stopwords[$normalised])) {
                continue;
            }
            $out[$normalised] = true;
        }

        // strval, because PHP casts a numeric array key to int: a token of "500"
        // came back as int(500) and stopped matching the strings every other
        // caller compares it against, the issue-token index included.
        return array_map('strval', array_keys($out));
    }

    /**
     * The ONLY morphology applied is a trailing plural 's'.
     *
     * Real stemming was considered and rejected: crude suffix stripping turns
     * "saving" into "sav" and matches it against nothing, while a proper stemmer
     * is a dependency this codebase does not have. Issue titles repeat terms
     * verbatim often enough that the trade is not worth it — and the cost is
     * visible and bounded (a missed match), not silent (a wrong one).
     */
    private function normalise(string $token): string
    {
        if (mb_strlen($token) >= 4 && str_ends_with($token, 's') && ! str_ends_with($token, 'ss')) {
            return mb_substr($token, 0, -1);
        }

        return $token;
    }

    /** Two empty sets score 0, not 1. No evidence is not agreement. */
    public function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $setA = array_flip($a);
        $intersection = 0;
        foreach (array_unique($b) as $token) {
            if (isset($setA[$token])) {
                $intersection++;
            }
        }

        $union = count(array_unique($a)) + count(array_unique($b)) - $intersection;

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    public function shared(array $a, array $b): array
    {
        $shared = array_values(array_intersect(array_unique($a), array_unique($b)));
        sort($shared);

        return $shared;
    }

    /**
     * The tokens worth indexing for one issue: the title carries more signal than
     * the body, so it is never truncated away.
     */
    public function indexTokens(?string $title, ?string $body, int $limit = 40): array
    {
        $titleTokens = $this->tokenize($title);
        $bodyTokens = array_diff($this->tokenize($body), $titleTokens);

        return array_slice(array_merge($titleTokens, array_values($bodyTokens)), 0, $limit);
    }
}
