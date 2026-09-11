<?php

namespace Tests\Unit\Sire;

use App\Services\Sire\Ai\SireRiskEngine;
use Tests\TestCase;

/**
 * The three risk engines against the SAME fixture the JS reference runs:
 * implementation/fixtures/risk-engine-cases.json
 *
 * Scores are asserted exactly. A risk band nobody can reproduce is a risk band
 * nobody acts on — and the fixture has already caught one calibration error in
 * this engine (an empty input announcing a finding).
 */
class SireRiskEngineTest extends TestCase
{
    private static function spec(): array
    {
        foreach ([
            base_path('../tests/fixtures/risk-engine-cases.json'),
            base_path('tests/fixtures/risk-engine-cases.json'),
        ] as $path) {
            if (is_file($path)) {
                return json_decode(file_get_contents($path), true);
            }
        }

        self::fail('risk-engine-cases.json not found. Vendor it per INTEGRATION.md.');
    }

    public static function regressionProvider(): array
    {
        return collect(self::spec()['regression_cases'])->mapWithKeys(fn ($c) => [$c['name'] => [$c]])->all();
    }

    public static function recurrenceProvider(): array
    {
        return collect(self::spec()['recurrence_cases'])->mapWithKeys(fn ($c) => [$c['name'] => [$c]])->all();
    }

    public static function releaseProvider(): array
    {
        return collect(self::spec()['release_cases'])->mapWithKeys(fn ($c) => [$c['name'] => [$c]])->all();
    }

    /** @dataProvider regressionProvider */
    public function test_regression_risk_matches_the_shared_specification(array $case): void
    {
        $this->assertMatches((new SireRiskEngine())->regressionRisk($case['input']), $case['expect']);
    }

    /** @dataProvider recurrenceProvider */
    public function test_recurrence_risk_matches_the_shared_specification(array $case): void
    {
        $this->assertMatches((new SireRiskEngine())->recurrenceRisk($case['input']), $case['expect']);
    }

    /** @dataProvider releaseProvider */
    public function test_release_risk_matches_the_shared_specification(array $case): void
    {
        $this->assertMatches((new SireRiskEngine())->releaseRisk($case['input']), $case['expect']);
    }

    public function test_the_thresholds_in_the_fixture_match_the_code(): void
    {
        $this->assertSame(self::spec()['thresholds']['high'], SireRiskEngine::THRESHOLD_HIGH);
        $this->assertSame(self::spec()['thresholds']['medium'], SireRiskEngine::THRESHOLD_MEDIUM);
    }

    public function test_one_banding_scale_serves_all_three_engines(): void
    {
        // A HIGH on a release and a HIGH on an issue must mean a comparable weight
        // of evidence, or the word stops carrying information.
        $engine = new SireRiskEngine();

        $this->assertSame('high', $engine->band(SireRiskEngine::THRESHOLD_HIGH));
        $this->assertSame('medium', $engine->band(SireRiskEngine::THRESHOLD_HIGH - 1));
        $this->assertSame('medium', $engine->band(SireRiskEngine::THRESHOLD_MEDIUM));
        $this->assertSame('low', $engine->band(SireRiskEngine::THRESHOLD_MEDIUM - 1));
        $this->assertSame('low', $engine->band(-5));
    }

    public function test_an_empty_input_is_low_and_unevidenced_not_reassuring(): void
    {
        // Nothing found is "no evidence either way", not "all clear". The services
        // return null on this rather than recording a comforting LOW.
        $engine = new SireRiskEngine();

        foreach (['regressionRisk', 'recurrenceRisk', 'releaseRisk'] as $method) {
            $result = $engine->{$method}([]);
            $this->assertSame('low', $result['level']);
            $this->assertTrue($engine->isUnevidenced($result), "{$method} invented a factor from nothing");
        }
    }

    public function test_zero_tests_and_unmeasured_tests_are_different_claims(): void
    {
        $engine = new SireRiskEngine();

        $measured = $engine->regressionRisk(['active_test_count' => 0]);
        $unmeasured = $engine->regressionRisk([]);

        $this->assertContains('no_tests', array_column($measured['factors'], 'key'));
        $this->assertNotContains('no_tests', array_column($unmeasured['factors'], 'key'));
    }

    public function test_every_factor_that_fires_carries_a_usable_sentence(): void
    {
        $result = (new SireRiskEngine())->regressionRisk([
            'module' => 'sales', 'module_regression_rate' => 0.3, 'module_issue_count' => 10,
            'severity_code' => 'critical', 'active_test_count' => 0,
        ]);

        foreach ($result['factors'] as $factor) {
            $this->assertNotSame(0, $factor['weight'], 'a zero-weight factor should be dropped');
            $this->assertGreaterThan(10, mb_strlen($factor['detail']), "{$factor['key']} has no usable reason");
        }
        // Heaviest first: the reader should meet the strongest argument immediately.
        $weights = array_column($result['factors'], 'weight');
        $sorted = $weights;
        rsort($sorted);
        $this->assertSame($sorted, $weights);
    }

    public function test_release_risk_never_claims_to_be_a_gate(): void
    {
        $result = (new SireRiskEngine())->releaseRisk([
            'open_critical' => 5, 'qa_failed' => 9, 'regression_count' => 9,
            'total_issues' => 90, 'qa_pass_rate' => 0.1,
        ]);

        $this->assertSame('high', $result['level']);
        $this->assertArrayNotHasKey('blocking', $result, 'a risk opinion must not carry a blocking flag');
        $this->assertArrayNotHasKey('gate', $result);
    }

    private function assertMatches(array $result, array $expect): void
    {
        $keys = array_column($result['factors'], 'key');

        if (isset($expect['score'])) {
            $this->assertSame($expect['score'], $result['score'], 'score');
        }
        if (isset($expect['level'])) {
            $this->assertSame($expect['level'], $result['level'], 'level');
        }
        if (isset($expect['factor_count'])) {
            $this->assertCount($expect['factor_count'], $result['factors']);
        }
        if (isset($expect['unevidenced'])) {
            $this->assertSame($expect['unevidenced'], (new SireRiskEngine())->isUnevidenced($result));
        }
        if (isset($expect['top_factor'])) {
            $this->assertSame($expect['top_factor'], $keys[0] ?? null, 'heaviest factor first');
        }
        if (isset($expect['excludes_factor'])) {
            $this->assertNotContains($expect['excludes_factor'], $keys);
        }
        foreach ($expect['includes_factors'] ?? [] as $key) {
            $this->assertContains($key, $keys);
        }
    }
}
