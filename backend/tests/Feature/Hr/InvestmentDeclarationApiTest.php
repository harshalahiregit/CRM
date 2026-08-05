<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrInvestmentDeclaration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The declaration lifecycle: Draft → Submitted → Verified | Rejected.
 *
 * The contract worth protecting is that the DECLARED figure survives verification.
 * Payroll records what it allowed in a separate column; it never overwrites what
 * the employee claimed, because that claim is the evidence.
 */
class InvestmentDeclarationApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private HrEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, 999] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'Tenant '.$id, 'slug' => 'tenant-'.$id,
                'subdomain' => 'tenant'.$id, 'status' => 'active',
            ])->save();
        }

        $this->employee = HrEmployee::create([
            'tenant_id' => self::TENANT, 'name' => 'Declarant', 'employee_code' => 'DEC-1',
            'department' => 'Engineering', 'designation' => 'Engineer', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ]);
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

    private function openDeclaration(): array
    {
        return $this->getJson("/api/hr/payroll/declarations/employee/{$this->employee->id}?financial_year=2026-2027")
            ->assertOk()->json();
    }

    private function withItems(int $id, array $items = [['80C', 120000]]): array
    {
        return $this->putJson("/api/hr/payroll/declarations/{$id}", [
            'regime' => 'old',
            'items' => array_map(fn ($i) => [
                'section' => $i[0], 'declared_amount' => $i[1], 'particulars' => 'Test line',
            ], $items),
        ])->assertOk()->json();
    }

    /* ── Meta + creation ──────────────────────────────────────────────── */

    public function test_meta_serves_the_vocabulary_the_form_renders_from(): void
    {
        $this->actAsHr();

        $res = $this->getJson('/api/hr/payroll/declarations/meta')->assertOk();

        $this->assertContains('80C', array_column($res->json('sections'), 'code'));
        $this->assertSame(['old', 'new'], $res->json('regimes'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $res->json('current_fy'));
    }

    public function test_opening_a_declaration_creates_an_empty_draft(): void
    {
        $this->actAsHr();

        $d = $this->openDeclaration();

        $this->assertSame('Draft', $d['status']);
        $this->assertSame('2026-2027', $d['financial_year']);
        $this->assertFalse($d['counts_for_tax'], 'an empty draft changes no tax figure');
        $this->assertSame([], $d['items']);
    }

    public function test_opening_it_twice_does_not_create_a_second_row(): void
    {
        $this->actAsHr();

        $first  = $this->openDeclaration();
        $second = $this->openDeclaration();

        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('hr_investment_declarations', 1);
    }

    /* ── Saving ───────────────────────────────────────────────────────── */

    public function test_items_and_totals_are_saved(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();

        $saved = $this->withItems($d['id'], [['80C', 120000], ['80D', 20000]]);

        $this->assertSame('old', $saved['regime']);
        $this->assertCount(2, $saved['items']);
        $this->assertEquals(140000, $saved['declared_total']);
        $this->assertNull($saved['items'][0]['verified_amount'], 'unverified is null, not zero');
    }

    public function test_saving_replaces_items_rather_than_appending(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();

        $this->withItems($d['id'], [['80C', 120000], ['80D', 20000]]);
        $saved = $this->withItems($d['id'], [['80C', 50000]]);

        $this->assertCount(1, $saved['items'], 'a removed line must not survive the save');
        $this->assertEquals(50000, $saved['declared_total']);
    }

    public function test_an_unknown_section_is_refused(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();

        $this->putJson("/api/hr/payroll/declarations/{$d['id']}", [
            'items' => [['section' => '80ZZZ', 'declared_amount' => 1000]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.section');
    }

    public function test_hra_and_previous_employer_details_are_saved(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();

        $saved = $this->putJson("/api/hr/payroll/declarations/{$d['id']}", [
            'previous_employer_income' => 300000,
            'previous_employer_tds'    => 25000,
            'hra' => ['rent_paid_annual' => 240000, 'metro' => true, 'months' => 12, 'landlord_pan' => 'ABCDE1234F'],
        ])->assertOk()->json();

        $this->assertEquals(300000, $saved['previous_employer_income']);
        $this->assertEquals(240000, $saved['hra']['rent_paid_annual']);
        $this->assertTrue($saved['hra']['metro']);
    }

    /* ── Lifecycle ────────────────────────────────────────────────────── */

    public function test_an_empty_declaration_cannot_be_submitted(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();

        $this->postJson("/api/hr/payroll/declarations/{$d['id']}/submit")->assertStatus(422);
    }

    public function test_a_submitted_declaration_is_locked_to_the_employee(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();
        $this->withItems($d['id']);

        $this->postJson("/api/hr/payroll/declarations/{$d['id']}/submit")
            ->assertOk()->assertJsonPath('status', 'Submitted');

        $this->putJson("/api/hr/payroll/declarations/{$d['id']}", ['items' => []])
            ->assertStatus(422);
    }

    public function test_submitting_does_not_yet_reduce_tax(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();
        $this->withItems($d['id']);

        $submitted = $this->postJson("/api/hr/payroll/declarations/{$d['id']}/submit")->assertOk()->json();

        $this->assertFalse($submitted['counts_for_tax'],
            'a claim is not evidence — only verification may reduce a deduction');
    }

    public function test_verification_records_what_was_allowed_without_erasing_the_claim(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();
        $saved = $this->withItems($d['id'], [['80C', 120000]]);
        $this->postJson("/api/hr/payroll/declarations/{$d['id']}/submit");

        $verified = $this->postJson("/api/hr/payroll/declarations/{$d['id']}/verify", [
            'items' => [['id' => $saved['items'][0]['id'], 'verified_amount' => 80000, 'remarks' => 'Partial proof']],
        ])->assertOk()->json();

        $this->assertSame('Verified', $verified['status']);
        $this->assertTrue($verified['counts_for_tax']);
        $this->assertEquals(120000, $verified['items'][0]['declared_amount'], 'the claim survives');
        $this->assertEquals(80000, $verified['items'][0]['verified_amount'], 'alongside what was allowed');
        $this->assertEquals(80000, $verified['verified_total']);
    }

    public function test_an_item_not_named_in_the_verify_payload_defaults_to_the_declared_figure(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();
        $saved = $this->withItems($d['id'], [['80C', 120000], ['80D', 20000]]);
        $this->postJson("/api/hr/payroll/declarations/{$d['id']}/submit");

        // Only 80C is corrected; 80D is accepted as declared.
        $verified = $this->postJson("/api/hr/payroll/declarations/{$d['id']}/verify", [
            'items' => [['id' => $saved['items'][0]['id'], 'verified_amount' => 100000]],
        ])->assertOk()->json();

        $this->assertEquals(120000, $verified['verified_total'], '100,000 + 20,000');
    }

    public function test_a_verified_declaration_is_locked_until_reopened(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();
        $this->withItems($d['id']);
        $this->postJson("/api/hr/payroll/declarations/{$d['id']}/submit");
        $this->postJson("/api/hr/payroll/declarations/{$d['id']}/verify", []);

        $this->putJson("/api/hr/payroll/declarations/{$d['id']}", ['items' => []])->assertStatus(422);

        $this->postJson("/api/hr/payroll/declarations/{$d['id']}/reopen")
            ->assertOk()->assertJsonPath('status', 'Draft')->assertJsonPath('counts_for_tax', false);

        $this->putJson("/api/hr/payroll/declarations/{$d['id']}", ['items' => []])->assertOk();
    }

    public function test_only_a_submitted_declaration_can_be_verified(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();
        $this->withItems($d['id']);

        $this->postJson("/api/hr/payroll/declarations/{$d['id']}/verify", [])->assertStatus(422);
    }

    public function test_a_rejected_declaration_does_not_count_for_tax(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();
        $this->withItems($d['id']);
        $this->postJson("/api/hr/payroll/declarations/{$d['id']}/submit");

        $rejected = $this->postJson("/api/hr/payroll/declarations/{$d['id']}/reject",
            ['remarks' => 'Proof does not match the claim'])->assertOk()->json();

        $this->assertSame('Rejected', $rejected['status']);
        $this->assertFalse($rejected['counts_for_tax']);
    }

    /* ── Tenancy ──────────────────────────────────────────────────────── */

    public function test_one_tenant_cannot_read_another_tenants_declaration(): void
    {
        $this->actAsHr();
        $d = $this->openDeclaration();

        $this->actAsHr(999);

        $this->getJson("/api/hr/payroll/declarations/{$d['id']}")->assertStatus(404);
        $this->getJson('/api/hr/payroll/declarations')->assertOk()->assertJsonCount(0, 'data');
    }

    /* ── Uniqueness ───────────────────────────────────────────────────── */

    public function test_one_declaration_per_employee_per_year_is_enforced_in_the_database(): void
    {
        $this->actAsHr();
        $this->openDeclaration();

        // A duplicate would split a year's deductions across two rows, and the
        // engine reads only one — so the constraint is in the schema, not just here.
        $this->expectException(\Illuminate\Database\QueryException::class);

        HrInvestmentDeclaration::create([
            'tenant_id' => self::TENANT, 'employee_id' => $this->employee->id,
            'financial_year' => '2026-2027', 'regime' => 'new', 'status' => 'Draft',
        ]);
    }
}
