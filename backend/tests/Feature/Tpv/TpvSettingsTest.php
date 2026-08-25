<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tpv\TpvSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §34 System Configuration — the config/constant baselines are now tenant-editable
 * through /api/tpv/settings, and the engines read the effective values. This guards:
 *  • an empty override table returns exactly the shipped defaults;
 *  • an admin's save is deep-merged and drives the engine (strike limit);
 *  • non-admins cannot write;
 *  • the VPI weight-sum guard rejects a skewed set;
 *  • reset reverts a group to defaults.
 */
class TpvSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    public function test_index_returns_builtins_and_effective_defaults(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $res = $this->getJson('/api/tpv/settings')->assertOk();
        $res->assertJsonPath('data.strike_rules.builtins.limit', 3);
        $res->assertJsonPath('data.strike_rules.effective.limit', 3);
        $res->assertJsonPath('data.strike_rules.custom', null);
        // all six groups present
        foreach (['strike_rules', 'vpi', 'approval_workflow', 'authority_matrix', 'approval_types', 'gate'] as $g) {
            $res->assertJsonPath("data.$g.effective", fn ($v) => is_array($v) && $v !== []);
        }
    }

    public function test_admin_can_override_strike_rules_and_engine_sees_it(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->putJson('/api/tpv/settings/strike_rules', [
            'limit' => 5, 'warn_at' => 4, 'critical_terminates_immediately' => false,
        ])->assertOk()->assertJsonPath('data.effective.limit', 5);

        // the catalog (what the engine reads) now reflects the override…
        $eff = app(TpvSettings::class)->strike(self::TENANT);
        $this->assertSame(5, $eff['limit']);
        $this->assertFalse($eff['critical_terminates_immediately']);
        // …and a baseline key the payload didn't touch is preserved by the merge.
        $this->assertSame(['Minor', 'Major', 'Critical'], $eff['severities']);
    }

    public function test_non_admin_cannot_write_settings(): void
    {
        Sanctum::actingAs($this->user('staff'));
        // staff is outside role:admin on this route group → 403
        $this->putJson('/api/tpv/settings/gate', ['ppe_enforcement' => 'off'])->assertForbidden();
    }

    public function test_vpi_weight_sum_is_enforced(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $bad = [
            'weights' => ['safety' => 0.9, 'compliance' => 0.9], // sums to 1.8
            'deductions' => ['ncr_open' => 8],
            'doc_expiring_window_days' => 30,
            'bands' => ['A' => 85, 'B' => 72, 'C' => 58, 'D' => 42],
        ];
        $this->putJson('/api/tpv/settings/vpi', $bad)->assertStatus(422);
    }

    public function test_reset_reverts_to_defaults(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->putJson('/api/tpv/settings/gate', ['ppe_enforcement' => 'deny'])->assertOk();
        $this->assertSame('deny', app(TpvSettings::class)->gate(self::TENANT)['ppe_enforcement']);

        $this->deleteJson('/api/tpv/settings/gate')->assertOk()->assertJsonPath('data.custom', null);
        $this->assertSame('warn', app(TpvSettings::class)->gate(self::TENANT)['ppe_enforcement']);
    }
}
