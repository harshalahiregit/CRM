<?php

namespace App\Support\Hr;

/**
 * Review comment #43 — "system indicate relevant skills and score of individual
 * to analyse".
 *
 * Compares what a position expects against what a person has, and reports the
 * overlap plus a percentage. The score is deliberately simple and explainable:
 * matched ÷ expected. Anything cleverer (weighting, proficiency levels) would be
 * a judgement nobody has specified, and an unexplainable number on an HR screen
 * is worse than no number.
 *
 * Matching is case- and punctuation-insensitive because skill lists are typed by
 * hand in three different places — "Node.js", "node js" and "NodeJS" are the same
 * skill and must not read as a gap.
 */
final class SkillMatcher
{
    /**
     * @param  array  $expected  the position's skills
     * @param  array  $held      the person's skills
     * @return array{score: float, matched: array, missing: array, extra: array, expected_count: int}
     */
    public static function compare(?array $expected, ?array $held): array
    {
        $expected = self::clean($expected);
        $held     = self::clean($held);

        // No expectation means nothing to score against. Returning 0 would read as
        // a total mismatch; null says "not applicable", which is the truth.
        if ($expected === []) {
            return ['score' => null, 'matched' => [], 'missing' => [],
                    'extra' => array_values($held), 'expected_count' => 0];
        }

        $heldKeys = array_map([self::class, 'key'], $held);

        $matched = $missing = [];
        foreach ($expected as $skill) {
            if (in_array(self::key($skill), $heldKeys, true)) {
                $matched[] = $skill;
            } else {
                $missing[] = $skill;
            }
        }

        $expectedKeys = array_map([self::class, 'key'], $expected);
        $extra = array_values(array_filter($held, fn ($s) => ! in_array(self::key($s), $expectedKeys, true)));

        return [
            'score'          => round(count($matched) / count($expected) * 100, 1),
            'matched'        => $matched,
            'missing'        => $missing,
            'extra'          => $extra,
            'expected_count' => count($expected),
        ];
    }

    /** Trim, drop blanks, de-duplicate — preserving the first spelling seen. */
    public static function clean(?array $skills): array
    {
        $out = [];
        $seen = [];

        foreach ($skills ?? [] as $skill) {
            if (! is_string($skill) && ! is_numeric($skill)) {
                continue;
            }
            $trimmed = trim((string) $skill);
            if ($trimmed === '') {
                continue;
            }
            $key = self::key($trimmed);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $trimmed;
        }

        return $out;
    }

    /** Comparison key: lowercase, letters and digits only. */
    private static function key(string $skill): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($skill))) ?: mb_strtolower(trim($skill));
    }
}
