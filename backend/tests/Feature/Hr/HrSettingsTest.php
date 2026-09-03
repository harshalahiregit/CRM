<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\AdvanceTierService;
use App\Services\Settings\SettingsService;
use App\Support\Hr\AdvanceStage;
use App\Support\Hr\HrSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HR settings, and the advance thresholds that make them worth having.
 *
 * SangoeTrack's tiers were fixed in code, so a small advance took the same three
 * signatures as a large one. The thresholds change that — and they default to
 * zero, meaning behaviour is unchanged until somebody deliberately sets them.
 */
class HrSettingsTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'set-t', 'status' => 'active']);
    }

    private function user(string $email, string $role = 'staff', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => explode('@', $email)[0], 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
            'internal_role' => $internal,
        ]);
    }

    private function employee(string $code, ?User $user = null, ?int $managerId = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $user?->id, 'reporting_manager_id' => $managerId,
        ]);
    }

    private function set(array $values): void
    {
        app(SettingsService::class)->setGroup($this->tenant()->id, HrSetting::GROUP, $values);
    }

    /** Requester with a real manager, plus accounts and a director. */
    private function cast(): array
    {
        $managerUser = $this->user('manager@example.test');
        $manager     = $this->employee('SNE-M', $managerUser);
        $staffUser   = $this->user('priya@example.test');
        $staff       = $this->employee('SNE-1', $staffUser, $manager->id);

        return [$staff, $staffUser, $managerUser,
            $this->user('accounts@example.test', 'staff', 'accounts'),
            $this->user('director@example.test', 'staff', 'director')];
    }

    private function raise(HrEmployee $e, User $actor, float $amount): HrAdvance
    {
        return app(AdvanceService::class)->request($e, [
            'purpose' => 'Site visit', 'amount_requested' => $amount,
        ], $actor);
    }

    /* ── the store ───────────────────────────────────────────────────── */

    public function test_defaults_come_back_before_anything_is_saved(): void
    {
        $values = app(SettingsService::class)->getGroup($this->tenant()->id, HrSetting::GROUP);

        $this->assertSame('09:30', $values['company_start_time']);
        $this->assertSame(0.0, (float) $values['advance_manager_limit'], 'Zero means no shortcut.');
        $this->assertTrue((bool) $values['advance_require_distinct_approvers']);
    }

    /**
     * SettingsService::set() silently does nothing for a key the registry does
     * not know, so a screen whose saves went nowhere would look like it worked.
     */
    public function test_every_declared_setting_is_actually_registered(): void
    {
        foreach (HrSetting::keys() as $key) {
            $this->assertTrue(
                \App\Support\Settings\SettingRegistry::has(HrSetting::GROUP, $key),
                "{$key} is declared but not registered — saving it would fail silently."
            );
        }
    }

    public function test_saving_persists_and_reads_back_with_the_right_type(): void
    {
        $this->set(['advance_manager_limit' => '5000', 'ip_restrict' => 'false', 'late_grace_minutes' => '20']);

        $v = app(SettingsService::class)->getGroup($this->tenant()->id, HrSetting::GROUP);

        $this->assertSame(5000.0, (float) $v['advance_manager_limit']);
        $this->assertIsBool($v['ip_restrict']);
        // The string "false" is truthy; reading it as a boolean is how a safety
        // setting turns itself back on.
        $this->assertFalse($v['ip_restrict']);
        $this->assertSame(20, (int) $v['late_grace_minutes']);
    }

    public function test_settings_do_not_leak_between_tenants(): void
    {
        $this->set(['advance_manager_limit' => 5000]);
        $other = Tenant::create(['name' => 'O', 'slug' => 'set-o', 'status' => 'active']);

        $v = app(SettingsService::class)->getGroup($other->id, HrSetting::GROUP);
        $this->assertSame(0.0, (float) $v['advance_manager_limit']);
    }

    /* ── the thresholds ──────────────────────────────────────────────── */

    public function test_a_small_advance_needs_only_the_manager(): void
    {
        [$staff, $staffUser, $manager] = $this->cast();
        $this->set(['advance_manager_limit' => 5000]);

        $advance = $this->raise($staff, $staffUser, 3000);
        $advance = app(AdvanceService::class)->approve($advance, $manager);

        $this->assertSame(AdvanceStage::APPROVED, $advance->status,
            'Under the manager limit, one approval finishes it.');
    }

    public function test_a_mid_sized_advance_stops_at_accounts(): void
    {
        [$staff, $staffUser, $manager, $accounts] = $this->cast();
        $this->set(['advance_manager_limit' => 5000, 'advance_accounts_limit' => 50000]);

        $advance = $this->raise($staff, $staffUser, 20000);
        $advance = app(AdvanceService::class)->approve($advance, $manager);
        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->status);

        $advance = app(AdvanceService::class)->approve($advance, $accounts);
        $this->assertSame(AdvanceStage::APPROVED, $advance->status, 'No director needed under the accounts limit.');
    }

    public function test_a_large_advance_still_climbs_the_whole_ladder(): void
    {
        [$staff, $staffUser, $manager, $accounts, $director] = $this->cast();
        $this->set(['advance_manager_limit' => 5000, 'advance_accounts_limit' => 50000]);

        $advance = $this->raise($staff, $staffUser, 200000);
        $advance = app(AdvanceService::class)->approve($advance, $manager);
        $advance = app(AdvanceService::class)->approve($advance, $accounts);
        $this->assertSame(AdvanceStage::ACCOUNTS_APPROVED, $advance->status);

        $advance = app(AdvanceService::class)->approve($advance, $director);
        $this->assertSame(AdvanceStage::APPROVED, $advance->status);
    }

    /** Zero is the default and must mean "no shortcut", not "everything qualifies". */
    public function test_zero_thresholds_leave_the_full_ladder(): void
    {
        [$staff, $staffUser, $manager] = $this->cast();

        $advance = $this->raise($staff, $staffUser, 1);
        $advance = app(AdvanceService::class)->approve($advance, $manager);

        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->status,
            'With no thresholds set, even ₹1 takes all three.');
    }

    public function test_the_drawn_ladder_only_shows_the_rungs_the_amount_needs(): void
    {
        [$staff, $staffUser] = $this->cast();
        $this->set(['advance_manager_limit' => 5000]);

        $advance = $this->raise($staff, $staffUser, 3000);

        $this->assertSame([AdvanceStage::MANAGER], app(AdvanceTierService::class)->ladderFor($advance));
    }

    /* ── the distinct-approver rule is now settable ──────────────────── */

    public function test_relaxing_the_rule_lets_one_person_approve_twice(): void
    {
        [$staff, $staffUser] = $this->cast();
        $admin = $this->user('admin@example.test', 'admin');

        $this->set(['advance_require_distinct_approvers' => false]);

        $advance = $this->raise($staff, $staffUser, 20000);
        $advance = app(AdvanceService::class)->approve($advance, $admin);
        $advance = app(AdvanceService::class)->approve($advance, $admin);

        $this->assertSame(AdvanceStage::ACCOUNTS_APPROVED, $advance->status);
    }

    public function test_it_is_strict_by_default(): void
    {
        [$staff, $staffUser] = $this->cast();
        $admin = $this->user('admin@example.test', 'admin');

        $advance = $this->raise($staff, $staffUser, 20000);
        app(AdvanceService::class)->approve($advance, $admin);

        $this->expectException(\App\Exceptions\BusinessException::class);
        app(AdvanceService::class)->approve($advance->fresh(), $admin);
    }

    /* ── over HTTP ───────────────────────────────────────────────────── */

    public function test_the_endpoints_are_gated_and_round_trip(): void
    {
        $staff = $this->user('nobody@example.test');
        $admin = $this->user('admin@example.test', 'admin');

        Sanctum::actingAs($staff);
        $this->getJson('/api/hr/settings')->assertStatus(403);
        $this->putJson('/api/hr/settings', ['company_start_time' => '10:00'])->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->getJson('/api/hr/settings')
            ->assertOk()
            ->assertJsonStructure(['data' => ['values', 'schema']]);

        $this->putJson('/api/hr/settings', ['company_start_time' => '10:00', 'advance_manager_limit' => 5000])
            ->assertOk()
            ->assertJsonPath('data.values.company_start_time', '10:00');

        // A partial save must not reset everything else to defaults.
        $this->putJson('/api/hr/settings', ['late_grace_minutes' => 25])->assertOk();
        $this->getJson('/api/hr/settings')->assertOk()->assertJsonPath('data.values.company_start_time', '10:00');
    }

    public function test_a_bad_value_is_refused(): void
    {
        Sanctum::actingAs($this->user('admin@example.test', 'admin'));

        $this->putJson('/api/hr/settings', ['company_start_time' => 'lunchtime'])->assertStatus(422);
        $this->putJson('/api/hr/settings', ['advance_manager_limit' => -5])->assertStatus(422);
        $this->putJson('/api/hr/settings', ['hr_notification_email' => 'not-an-email'])->assertStatus(422);
    }

    /** An employee has no business reading approval thresholds. */
    public function test_the_employee_view_exposes_only_a_short_allowlist(): void
    {
        $staffUser = $this->user('priya@example.test');
        $this->employee('SNE-1', $staffUser);
        $this->set(['advance_manager_limit' => 5000, 'hr_notification_email' => 'hr@example.test']);

        Sanctum::actingAs($staffUser);
        $data = $this->getJson('/api/hr/me/settings')->assertOk()->json('data');

        $this->assertArrayHasKey('company_start_time', $data);
        $this->assertArrayNotHasKey('advance_manager_limit', $data);
        $this->assertArrayNotHasKey('hr_notification_email', $data);
    }
}
