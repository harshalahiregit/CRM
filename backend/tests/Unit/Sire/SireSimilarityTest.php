<?php

namespace Tests\Unit\Sire;

use Sire\AI\SireSimilarityScorer;
use Sire\AI\SireTextAnalyzer;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Duplicate scoring against the SAME fixture the JS reference runs:
 * implementation/fixtures/similarity-cases.json
 *
 * The scores are asserted to four decimal places wherever the token sets are small
 * enough to verify by hand. A scorer whose numbers nobody can reproduce is a
 * scorer nobody should trust — and this one already caught an arithmetic error in
 * its own fixture.
 */
class SireSimilarityTest extends TestCase
{
    private static function spec(): array
    {
        foreach ([
            __DIR__.'/../../../packages/sire/tests/fixtures/similarity-cases.json',
            __DIR__.'/../../../packages/sire/tests/fixtures/similarity-cases.json',
        ] as $path) {
            if (is_file($path)) {
                return json_decode(file_get_contents($path), true);
            }
        }

        self::fail('similarity-cases.json not found. Vendor it per INTEGRATION.md.');
    }

    public static function tokenizeProvider(): array
    {
        return collect(self::spec()['tokenize_cases'])->mapWithKeys(fn (array $c) => [$c['name'] => [$c]])->all();
    }

    public static function scoreProvider(): array
    {
        return collect(self::spec()['score_cases'])->mapWithKeys(fn (array $c) => [$c['name'] => [$c]])->all();
    }

    private function scorer(): SireSimilarityScorer
    {
        return new SireSimilarityScorer(new SireTextAnalyzer());
    }

    /** @dataProvider tokenizeProvider */
    public function test_tokenization_matches_the_shared_specification(array $case): void
    {
        $actual = (new SireTextAnalyzer())->tokenize($case['input']);
        sort($actual);
        $expected = $case['expect'];
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    /** @dataProvider scoreProvider */
    public function test_scoring_matches_the_shared_specification(array $case): void
    {
        $opts = [];
        if (isset($case['rare_tokens'])) {
            $opts['rare_tokens'] = $case['rare_tokens'];
        }
        if (isset($case['now'])) {
            $opts['now'] = CarbonImmutable::parse($case['now']);
        }

        $result = $this->scorer()->score($case['query'], $case['candidate'], $opts);

        foreach ($case['expect'] as $key => $expected) {
            if (str_starts_with($key, '_')) {
                continue; // `_working` documents the arithmetic for a reader
            }
            if ($key === 'score') {
                $this->assertEqualsWithDelta($expected, $result['score'], 0.0001, 'score');

                continue;
            }
            if ($key === 'label') {
                $this->assertSame($expected, $result['label'], 'label');

                continue;
            }
            if (is_array($expected)) {
                $this->assertSame($expected, $result['signals'][$key], $key);

                continue;
            }
            $this->assertEqualsWithDelta($expected, $result['signals'][$key], 0.0001, $key);
        }
    }

    public function test_scoring_is_symmetric(): void
    {
        // Which issue was filed first must not change how alike they are.
        $a = ['title' => 'lead save fail', 'description' => 'spinner', 'module' => 'sales', 'section' => 'leads'];
        $b = ['title' => 'save lead error', 'description' => 'spinner run', 'module' => 'sales', 'section' => 'leads'];

        $this->assertSame(
            $this->scorer()->score($a, $b)['score'],
            $this->scorer()->score($b, $a)['score'],
        );
    }

    public function test_scores_stay_in_range_on_sparse_or_absurd_input(): void
    {
        $cases = [
            [[], []],
            [['title' => null], ['title' => null]],
            [['title' => str_repeat('a ', 5000)], ['title' => str_repeat('a ', 5000)]],
        ];

        foreach ($cases as [$q, $c]) {
            $score = $this->scorer()->score($q, $c)['score'];
            $this->assertGreaterThanOrEqual(0, $score);
            $this->assertLessThanOrEqual(1, $score);
        }
    }

    public function test_two_empty_token_sets_are_not_a_perfect_match(): void
    {
        // No evidence is not agreement. Returning 1.0 here would make every
        // description-less issue a duplicate of every other one.
        $this->assertSame(0.0, (new SireTextAnalyzer())->jaccard([], []));
    }

    public function test_every_result_carries_readable_evidence(): void
    {
        $q = ['title' => 'lead save fail 500', 'description' => 'spinner', 'module' => 'sales', 'section' => 'leads', 'screen' => 'lead-details', 'category' => 'bug'];
        $signals = $this->scorer()->score($q, $q)['signals'];

        // "92% similar" is a claim. These are evidence.
        $this->assertNotEmpty($signals['shared_terms']);
        $this->assertNotEmpty($signals['structural_matched']);
        $this->assertArrayHasKey('title_similarity', $signals);
    }
}
