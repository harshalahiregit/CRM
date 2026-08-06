<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrStatutoryRule;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The statutory rule book API.
 *
 * The contract worth protecting: this endpoint accepts any rate the business
 * configures and judges none of them, but it REFUSES a rule that could never fire
 * (PT with no state) or one the calculators cannot read (slabs missing/inverted).
 */
class StatutoryRuleApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, 999] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'Tenant '.$id, 'slug' => 'tenant-'.$id,
                'subdomain' => 'tenant'.$id, 'status' => 'active',
            ])->save();
        }
    }

    private function actAsHr(int $tenantId = self::TENANT): User
    {
        $user = User::create([
            'tenant_id' => $tenantId, 'name' => 'HR', 'email' => 'hr'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'rule_type'      => 'pt',
            'state'          => 'Maharashtra',
            'effective_from' => '2026-04-01',
            'config'         => ['slabs' => [['from' => 0, 'to' => null, 'amount' => 200]]],
            'is_active'      => true,
        ], $overrides);
    }

    /* ── Meta ─────────────────────────────────────────────────────────── */

    public function test_meta_serves_the_vocabulary_the_screen_renders_from(): void
    {
        $this->actAsHr();

        $res = $this->getJson('/api/hr/payroll/statutory/meta')->assertOk();

        $this->assertSame(HrStatutoryRule::TYPES, $res->json('rule_types'));
        $this->assertCount(36, $res->json('work_states'));
        $this->assertNull($res->json('defaults.default_work_state'), 'nothing is assumed by default');
    }

    /* ── CRUD ─────────────────────────────────────────────────────────── */

    public function test_a_rule_can_be_created_listed_and_deleted(): void
    {
        $this->actAsHr();

        $id = $this->postJson('/api/hr/payroll/statutory/rules', $this->payload())
            ->assertCreated()->json('id');

        $this->getJson('/api/hr/payroll/statutory/rules')
            ->assertOk()->assertJsonPath('data.0.rule_type', 'pt')
            ->assertJsonPath('data.0.state', 'Maharashtra');

        $this->deleteJson("/api/hr/payroll/statutory/rules/{$id}")->assertOk();
        $this->assertDatabaseCount('hr_statutory_rules', 0);
    }

    public function test_any_rate_the_business_configures_is_accepted_verbatim(): void
    {
        $this->actAsHr();

        // A deliberately non-standard rate: the API has no opinion on legal values.
        $res = $this->postJson('/api/hr/payroll/statutory/rules', $this->payload([
            'rule_type' => 'pf', 'state' => null,
            'config' => ['employee_rate' => 9.5, 'employer_rate' => 9.5, 'wage_ceiling' => 25000],
        ]))->assertCreated();

        $this->assertSame(9.5, $res->json('config.employee_rate'));
    }

    public function test_a_state_code_is_stored_canonically(): void
    {
        $this->actAsHr();

        $this->postJson('/api/hr/payroll/statutory/rules', $this->payload(['state' => 'KA']))
            ->assertCreated()
            ->assertJsonPath('state', 'Karnataka');
    }

    /* ── Refusals ─────────────────────────────────────────────────────── */

    public function test_a_pt_rule_without_a_state_is_refused(): void
    {
        $this->actAsHr();

        // It would be stored happily and then never match anything — a rule that
        // looks configured but silently deducts nothing is worse than an error.
        $this->postJson('/api/hr/payroll/statutory/rules', $this->payload(['state' => null]))
            ->assertStatus(422);
    }

    public function test_a_city_is_refused_as_a_state(): void
    {
        $this->actAsHr();

        $this->postJson('/api/hr/payroll/statutory/rules', $this->payload(['state' => 'Pune']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');
    }

    public function test_slabs_are_structurally_checked(): void
    {
        $this->actAsHr();

        $this->postJson('/api/hr/payroll/statutory/rules', $this->payload(['config' => ['slabs' => []]]))
            ->assertStatus(422);

        $this->postJson('/api/hr/payroll/statutory/rules', $this->payload([
            'config' => ['slabs' => [['from' => 10000, 'to' => 5000, 'amount' => 200]]],
        ]))->assertStatus(422);
    }

    public function test_an_unknown_rule_type_is_refused(): void
    {
        $this->actAsHr();

        $this->postJson('/api/hr/payroll/statutory/rules', $this->payload(['rule_type' => 'cess']))
            ->assertStatus(422)->assertJsonValidationErrors('rule_type');
    }

    /* ── Tenancy ──────────────────────────────────────────────────────── */

    public function test_one_tenant_cannot_see_or_delete_another_tenants_rules(): void
    {
        $this->actAsHr();
        $id = $this->postJson('/api/hr/payroll/statutory/rules', $this->payload())->json('id');

        $this->actAsHr(999);

        $this->getJson('/api/hr/payroll/statutory/rules')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/hr/payroll/statutory/rules/{$id}")->assertStatus(404);
    }

    /* ── Company defaults ─────────────────────────────────────────────── */

    public function test_the_company_default_work_state_round_trips_canonically(): void
    {
        $this->actAsHr();

        $this->putJson('/api/hr/payroll/statutory/defaults', ['default_work_state' => 'mh'])
            ->assertOk()->assertJsonPath('default_work_state', 'Maharashtra');

        $this->getJson('/api/hr/payroll/statutory/meta')
            ->assertJsonPath('defaults.default_work_state', 'Maharashtra');
    }

    public function test_the_company_default_can_be_cleared(): void
    {
        $this->actAsHr();
        $this->putJson('/api/hr/payroll/statutory/defaults', ['default_work_state' => 'Karnataka'])->assertOk();

        $this->putJson('/api/hr/payroll/statutory/defaults', ['default_work_state' => null])
            ->assertOk()->assertJsonPath('default_work_state', null);
    }
}
