<?php

namespace Tests\Unit\Sire;

use Sire\Contracts\SireSettingsProvider;
use Sire\Services\SireReleaseGateService;
use Mockery;
use Tests\TestCase;

/**
 * Release gates against the SAME fixture the JS reference runs:
 * implementation/fixtures/release-gates-cases.json
 *
 * A gate engine earns a shared decision table because its failure mode is silent
 * and expensive: a gate that quietly passes lets a broken release ship, and the
 * dashboard says READY.
 *
 * No database — evaluateSnapshot() is pure.
 */
class SireReleaseGateTest extends TestCase
{
    private static function spec(): array
    {
        foreach ([
            __DIR__.'/../../../packages/sire/tests/fixtures/release-gates-cases.json',
            __DIR__.'/../../../packages/sire/tests/fixtures/release-gates-cases.json',
        ] as $path) {
            if (is_file($path)) {
                return json_decode(file_get_contents($path), true);
            }
        }

        self::fail('release-gates-cases.json not found. Vendor it per INTEGRATION.md.');
    }

    public static function caseProvider(): array
    {
        return collect(self::spec()['cases'])->mapWithKeys(fn (array $c) => [$c['name'] => [$c]])->all();
    }

    private function service(): SireReleaseGateService
    {
        return new SireReleaseGateService(Mockery::mock(SireSettingsProvider::class));
    }

    /** @dataProvider caseProvider */
    public function test_gate_evaluation_matches_the_shared_specification(array $case): void
    {
        $gates = $case['gates'] ?? SireReleaseGateService::DEFAULT_GATES;

        $result = $this->service()->evaluateSnapshot(
            $gates,
            $case['snapshot'],
            $case['override']['gates'] ?? null,
        );

        foreach ($case['expect'] as $key => $expected) {
            if ($key === 'gate_status') {
                foreach ($expected as $gateKey => $gateExpected) {
                    $gate = collect($result['gates'])->firstWhere('key', $gateKey);
                    $this->assertNotNull($gate, "gate {$gateKey} missing");
                    $this->assertSame($gateExpected, $gate['status'], "{$case['name']} → {$gateKey}");
                }

                continue;
            }
            $this->assertSame($expected, $result[$key], "{$case['name']} → {$key}");
        }
    }

    public function test_the_defaults_match_the_fixture_defaults(): void
    {
        $this->assertSame(
            array_column(self::spec()['default_gates'], 'key'),
            array_column(SireReleaseGateService::DEFAULT_GATES, 'key'),
        );
    }

    public function test_an_unknown_gate_key_blocks_rather_than_passing_quietly(): void
    {
        // A typo in settings must not silently disable governance.
        $result = $this->service()->evaluateSnapshot(
            [['key' => 'nope', 'enabled' => true, 'blocking' => true]],
            ['total_issues' => 1],
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(SireReleaseGateService::UNKNOWN, $result['gates'][0]['status']);
    }

    public function test_an_empty_gate_list_reports_ungoverned_rather_than_all_clear(): void
    {
        $result = $this->service()->evaluateSnapshot([], ['total_issues' => 5, 'open_critical' => 3]);

        $this->assertSame('ready', $result['status']);
        $this->assertTrue($result['ungoverned'], 'the UI must distinguish "passed" from "nothing was checked"');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
