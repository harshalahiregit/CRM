<?php

namespace Tests\Unit\Sire;

use App\Models\Sire\RecurrenceGroup;
use App\Services\Sire\SireAccessService;
use App\Services\Sire\SireQualityMetricsService;
use App\Services\Sire\SireRecurrenceService;
use App\Services\Numbering\DocumentNumberServiceInterface;
use App\Services\Sire\SireDuplicateService;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

/**
 * Phase 2 quality logic against the SAME fixtures the JS reference runs:
 *
 *   implementation/fixtures/recurrence-risk-cases.json
 *   implementation/fixtures/quality-metrics-cases.json
 *
 * Two implementations of one rule set drift. A shared decision table is how that
 * gets paid down — and on the SLA round it caught two real bugs on first run.
 *
 * No database: recurrence groups are unsaved models and the rate function is pure.
 */
class SireQualityFixtureTest extends TestCase
{
    private static function load(string $file): array
    {
        foreach ([base_path("../implementation/fixtures/{$file}"), base_path("tests/fixtures/{$file}")] as $path) {
            if (is_file($path)) {
                return json_decode(file_get_contents($path), true);
            }
        }

        self::fail("Fixture not found: {$file}. Vendor it per INTEGRATION.md.");
    }

    public static function riskProvider(): array
    {
        return collect(self::load('recurrence-risk-cases.json')['cases'])
            ->mapWithKeys(fn (array $c) => [$c['name'] => [$c]])
            ->all();
    }

    public static function metricProvider(): array
    {
        return collect(self::load('quality-metrics-cases.json')['cases'])
            ->mapWithKeys(fn (array $c) => [$c['name'] => [$c]])
            ->all();
    }

    /** @dataProvider riskProvider */
    public function test_recurrence_risk_matches_the_shared_specification(array $case): void
    {
        $group = new RecurrenceGroup();
        $group->occurrence_count = $case['group']['occurrence_count'];
        $group->average_interval_days = $case['group']['average_interval_days'];
        $group->permanent_fix_status = $case['group']['permanent_fix_status'];
        $group->latest_occurrence_at = $case['group']['latest_occurrence_at']
            ? CarbonImmutable::parse($case['group']['latest_occurrence_at'])
            : null;

        $service = new SireRecurrenceService(
            Mockery::mock(SireDuplicateService::class),
            Mockery::mock(DocumentNumberServiceInterface::class),
        );

        $result = $service->assessRisk($group, CarbonImmutable::parse($case['now']));

        foreach ($case['expect'] as $key => $expected) {
            $this->assertSame($expected, $result[$key], "{$case['name']} → {$key}");
        }
    }

    /** @dataProvider metricProvider */
    public function test_rates_match_the_shared_specification(array $case): void
    {
        $service = new SireQualityMetricsService(Mockery::mock(SireAccessService::class));

        [$numerator, $denominator] = match ($case['metric']) {
            'reopen_rate'       => [$case['input']['reopened'], $case['input']['resolved']],
            'qa_rejection_rate' => [$case['input']['qa_failed_cycles'], $case['input']['qa_cycles']],
            'regression_rate'   => [$case['input']['regressions'], $case['input']['created']],
            'recurrence_rate'   => [$case['input']['recurring'], $case['input']['created']],
        };

        $result = $service->rate($numerator, $denominator);

        foreach ($case['expect'] as $key => $expected) {
            if ($key === 'value' && $expected !== null) {
                $this->assertEqualsWithDelta($expected, $result[$key], 0.0001, "{$case['name']} → value");

                continue;
            }
            $this->assertSame($expected, $result[$key], "{$case['name']} → {$key}");
        }
    }

    public function test_a_zero_denominator_is_never_reported_as_zero_percent(): void
    {
        // The single most consequential line in this file. "0%" reads as perfect;
        // "—" reads as unmeasured, and only one of those is true.
        $service = new SireQualityMetricsService(Mockery::mock(SireAccessService::class));

        foreach ([[0, 0], [5, 0], [0, -1]] as [$n, $d]) {
            $result = $service->rate($n, $d);
            $this->assertNull($result['value']);
            $this->assertSame('—', $result['display']);
        }

        $this->assertSame('0%', $service->rate(0, 40)['display']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
