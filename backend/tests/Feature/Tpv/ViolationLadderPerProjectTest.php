<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvSetting;
use App\Support\Tpv\TpvSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §26 — the violation ladder can be overridden per project/client on top of the
 * tenant ladder.
 */
class ViolationLadderPerProjectTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_project_override_replaces_the_steps_for_that_project(): void
    {
        // Store a tenant override that carries a stricter ladder for one project.
        TpvSetting::create([
            'tenant_id' => self::TENANT, 'group' => 'violation_ladder',
            'payload' => [
                'project_overrides' => [
                    'Refinery' => ['steps' => [
                        ['points' => 0, 'level' => 'None'],
                        ['points' => 3, 'level' => 'Suspension'],
                    ]],
                ],
            ],
        ]);

        $settings = app(TpvSettings::class);

        $base = $settings->violationLadderFor(null, self::TENANT)['steps'];
        $refinery = $settings->violationLadderFor('Refinery', self::TENANT)['steps'];

        // The Refinery ladder is the stricter override, not the tenant baseline.
        $this->assertNotSame($base, $refinery);
        $this->assertSame('Suspension', $refinery[1]['level']);
        $this->assertSame(3, $refinery[1]['points']);

        // An unknown project falls back to the tenant ladder.
        $this->assertSame($base, $settings->violationLadderFor('Township', self::TENANT)['steps']);
    }
}
