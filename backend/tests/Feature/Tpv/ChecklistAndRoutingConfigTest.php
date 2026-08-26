<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Support\Tpv\TpvSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §10 configurable onboarding checklists + §12 dimension-based approval routing,
 * both via the tenant-editable TpvSettings pattern.
 */
class ChecklistAndRoutingConfigTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_checklist_merges_general_with_matching_rule(): void
    {
        $settings = app(TpvSettings::class);

        $low = $settings->checklistFor(['risk_level' => 'Low'], self::TENANT);
        $high = $settings->checklistFor(['risk_level' => 'High'], self::TENANT);

        // General items always present; the High rule adds more.
        $this->assertContains('Company profile complete', $low['items']);
        $this->assertContains('Job safety analysis reviewed', $high['items']);
        $this->assertNotContains('Job safety analysis reviewed', $low['items']);
        $this->assertTrue($high['gates_activation']);
    }

    public function test_routing_picks_the_matching_rule_else_default(): void
    {
        $settings = app(TpvSettings::class);

        $this->assertSame(['manager', 'head'], $settings->routeFor(['risk' => 'High'], self::TENANT));
        $this->assertSame(['manager'], $settings->routeFor(['risk' => 'Low'], self::TENANT));
        $this->assertSame(['manager', 'head', 'director'], $settings->routeFor(['value' => 'over_1cr'], self::TENANT));
    }
}
