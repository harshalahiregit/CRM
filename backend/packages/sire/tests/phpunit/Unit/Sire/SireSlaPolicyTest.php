<?php

namespace Tests\Unit\Sire;

use App\Models\Sire\Report;
use App\Models\Sire\ReportCategory;
use App\Models\Sire\ReportSeverity;
use App\Services\Settings\SettingsService;
use App\Services\Sire\SireSlaService;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

/**
 * SIRE SLA — runs the SAME fixture file as tests/reference/slaPolicy.mjs.
 *
 *     implementation/fixtures/sla-cases.json
 *
 * Two implementations of one set of rules is a drift risk; a shared decision table
 * is how that risk is paid down. If this test and the JS reference disagree, the
 * fixture is the arbiter — not whichever one was written most recently.
 *
 * No database: reports are unsaved models with their relations set by hand, so
 * this is a true unit test of the arithmetic.
 */
class SireSlaPolicyTest extends TestCase
{
    public static function fixtureProvider(): array
    {
        $path = base_path('../tests/fixtures/sla-cases.json');

        if (! is_file($path)) {
            // Adjust once the fixture is vendored into the repo; see INTEGRATION.md.
            $path = base_path('tests/fixtures/sla-cases.json');
        }

        $spec = json_decode(file_get_contents($path), true);

        return collect($spec['cases'])
            ->mapWithKeys(fn (array $case) => [$case['name'] => [$case, $spec['defaults']]])
            ->all();
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function test_sla_matches_the_shared_specification(array $case, array $defaults): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with(Mockery::any(), 'sire.sla.policies', Mockery::any())->andReturn($case['policies']);
        $settings->shouldReceive('get')->with(Mockery::any(), 'sire.sla.warning_threshold', Mockery::any())->andReturn($defaults['warning_threshold']);
        $settings->shouldReceive('get')->with(Mockery::any(), 'sire.sla.pause_states', Mockery::any())->andReturn($defaults['pause_states']);

        $report = $this->makeReport($case);
        $result = (new SireSlaService($settings))->for($report, CarbonImmutable::parse($case['now']));

        if (isset($case['expect']['source'])) {
            $this->assertSame($case['expect']['source'], $result['source'], 'source');
        }
        if (array_key_exists('matched_index', $case['expect'])) {
            $this->assertSame($case['expect']['matched_index'], $result['matched_index'], 'matched_index');
        }

        foreach (['ack', 'resolve'] as $clock) {
            if (! isset($case['expect'][$clock])) {
                continue;
            }
            foreach ($case['expect'][$clock] as $key => $expected) {
                if ($key === 'deadline') {
                    $this->assertTrue(
                        CarbonImmutable::parse($expected)->equalTo(CarbonImmutable::parse($result[$clock][$key])),
                        "{$clock}.deadline",
                    );

                    continue;
                }
                $this->assertSame($expected, $result[$clock][$key], "{$clock}.{$key}");
            }
        }
    }

    public function test_only_the_four_documented_states_are_ever_produced(): void
    {
        $allowed = [null, 'on_track', 'warning', 'breached', 'paused'];

        foreach (self::fixtureProvider() as [$case, $defaults]) {
            $settings = Mockery::mock(SettingsService::class);
            $settings->shouldReceive('get')->with(Mockery::any(), 'sire.sla.policies', Mockery::any())->andReturn($case['policies']);
            $settings->shouldReceive('get')->with(Mockery::any(), 'sire.sla.warning_threshold', Mockery::any())->andReturn($defaults['warning_threshold']);
            $settings->shouldReceive('get')->with(Mockery::any(), 'sire.sla.pause_states', Mockery::any())->andReturn($defaults['pause_states']);

            $result = (new SireSlaService($settings))->for($this->makeReport($case), CarbonImmutable::parse($case['now']));

            $this->assertContains($result['ack']['state'], $allowed);
            $this->assertContains($result['resolve']['state'], $allowed);
        }
    }

    private function makeReport(array $case): Report
    {
        $data = $case['report'];

        $report = new Report([
            'tenant_id' => 1,
            'priority'  => $data['priority'],
            'status'    => $data['status'],
        ]);
        $report->tenant_id = 1;
        $report->status = $data['status'];
        $report->priority = $data['priority'];
        $report->sla_started_at = $data['sla_started_at'];
        $report->acknowledged_at = $data['acknowledged_at'];
        $report->closed_at = $data['closed_at'];
        $report->sla_paused_minutes = $data['sla_paused_minutes'];
        $report->sla_paused_since = $data['sla_paused_since'];
        $report->created_at = $data['sla_started_at'];

        $category = new ReportCategory(['code' => $data['type']]);
        $category->code = $data['type'];
        $report->setRelation('category', $category);

        $severity = new ReportSeverity($case['severity']);
        foreach ($case['severity'] as $key => $value) {
            $severity->{$key} = $value;
        }
        $report->setRelation('severity', $severity);

        return $report;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
