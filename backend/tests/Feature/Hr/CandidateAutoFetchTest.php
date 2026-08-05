<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\CandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Review comment #15 — "ADD CANDIDATE – AUTO FETCH CANDIDATE DETAIL AND
 * (PRESENT CO., DEPT, DESIGNATION, REFERENCE".
 *
 * Company and name already worked. These cover the parts that did not:
 * DESIGNATION and DEPT (parsed but previously discarded — there was no column),
 * and REFERENCE (a column that existed but was captured nowhere).
 */
class CandidateAutoFetchTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->actor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'HR', 'email' => 'hr'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    /** A LinkedIn page whose og:title carries the given headline. */
    private function fakeProfile(string $headline, string $name = 'Asha Rao', string $company = 'Acme Corp'): void
    {
        $title = $company !== ''
            ? "{$name} - {$headline} at {$company} | LinkedIn"
            : "{$name} - {$headline} | LinkedIn";

        Http::fake(['*' => Http::response(
            '<html><head><meta property="og:title" content="'.$title.'"/>'
            .'<meta property="og:description" content="View profile · Pune, Maharashtra"/></head></html>', 200
        )]);
    }

    private function parse(string $url = 'https://www.linkedin.com/in/asha-rao'): array
    {
        return app(CandidateService::class)->linkedinParse($url)['data'];
    }

    /* ── DESIGNATION + DEPT ───────────────────────────────────────────── */

    public function test_a_comma_headline_yields_designation_and_department(): void
    {
        $this->fakeProfile('Senior Engineer, Platform Engineering');

        $data = $this->parse();

        $this->assertSame('Acme Corp', $data['current_company']);
        $this->assertSame('Senior Engineer', $data['current_designation']);
        $this->assertSame('Platform Engineering', $data['current_department']);
    }

    public function test_a_pipe_headline_yields_designation_and_department(): void
    {
        $this->fakeProfile('Engineering Manager | Data Platform');

        $data = $this->parse();

        $this->assertSame('Engineering Manager', $data['current_designation']);
        $this->assertSame('Data Platform', $data['current_department']);
    }

    public function test_an_en_dash_headline_is_split(): void
    {
        // "VP – Marketing" is far more common than the hyphen form.
        $this->fakeProfile('VP – Marketing');

        $data = $this->parse();

        $this->assertSame('VP', $data['current_designation']);
        $this->assertSame('Marketing', $data['current_department']);
    }

    public function test_a_plain_headline_yields_a_designation_and_no_department(): void
    {
        $this->fakeProfile('Senior Software Engineer');

        $data = $this->parse();

        $this->assertSame('Senior Software Engineer', $data['current_designation']);
        // Better blank than wrong: an invented department is one the recruiter
        // has to notice and clear.
        $this->assertNull($data['current_department']);
    }

    public function test_a_tagline_second_segment_is_not_treated_as_a_department(): void
    {
        $this->fakeProfile('Software Engineer | ex-Google');

        $data = $this->parse();

        $this->assertSame('Software Engineer', $data['current_designation']);
        $this->assertNull($data['current_department']);
    }

    public function test_a_long_marketing_strapline_is_not_a_department(): void
    {
        $this->fakeProfile('Founder | Helping startups scale their engineering teams faster');

        $data = $this->parse();

        $this->assertSame('Founder', $data['current_designation']);
        $this->assertNull($data['current_department']);
    }

    public function test_a_team_suffix_is_recognised_as_a_department(): void
    {
        $this->fakeProfile('Analyst, Risk Team');

        $this->assertSame('Risk Team', $this->parse()['current_department']);
    }

    public function test_a_profile_with_no_headline_returns_nulls_not_errors(): void
    {
        Http::fake(['*' => Http::response(
            '<html><head><meta property="og:title" content="Asha Rao | LinkedIn"/></head></html>', 200
        )]);

        $data = $this->parse();

        $this->assertNull($data['current_designation']);
        $this->assertNull($data['current_department']);
    }

    public function test_the_rate_limited_fallback_returns_the_same_keys(): void
    {
        // A caller must never have to branch on which path produced the payload.
        Http::fake(['*' => Http::response('nope', 429)]);

        $data = $this->parse();

        $this->assertArrayHasKey('current_designation', $data);
        $this->assertArrayHasKey('current_department', $data);
        $this->assertSame('Asha Rao', $data['name']);
    }

    /* ── Persistence ──────────────────────────────────────────────────── */

    public function test_the_fetched_fields_are_saved_on_the_candidate(): void
    {
        Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/candidates', [
            'name'                => 'Asha Rao',
            'email'               => 'asha'.uniqid().'@test.com',
            'source'              => 'LinkedIn',
            'current_company'     => 'Acme Corp',
            'current_designation' => 'Senior Engineer',
            'current_department'  => 'Platform Engineering',
            // REFERENCE — the column existed; nothing ever captured it.
            'professional_references' => ['Priya Nair — ex-manager — 98765 43210'],
        ])->assertCreated();

        $candidate = HrCandidate::first();
        $this->assertSame('Senior Engineer', $candidate->current_designation);
        $this->assertSame('Platform Engineering', $candidate->current_department);
        $this->assertSame(['Priya Nair — ex-manager — 98765 43210'], $candidate->professional_references);
    }

    public function test_the_new_fields_are_optional_so_existing_callers_still_work(): void
    {
        // Backward compatibility: the payload every existing client already sends.
        Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/candidates', [
            'name'   => 'Minimal',
            'email'  => 'min'.uniqid().'@test.com',
            'source' => 'LinkedIn',
        ])->assertCreated();

        $candidate = HrCandidate::first();
        $this->assertNull($candidate->current_designation);
        $this->assertNull($candidate->current_department);
    }

    /* ── REFERENCE (source / person) ──────────────────────────────────── */

    private function employee(string $name = 'Ravi Kumar', int $tenantId = self::TENANT): \App\Models\Hr\HrEmployee
    {
        return \App\Models\Hr\HrEmployee::create([
            'tenant_id' => $tenantId, 'name' => $name,
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Engineering', 'designation' => 'Engineer',
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);
    }

    public function test_an_employee_referral_resolves_the_name_from_the_employee(): void
    {
        Sanctum::actingAs($this->actor);
        $referrer = $this->employee('Ravi Kumar');

        $this->postJson('/api/hr/candidates', [
            'name' => 'Asha Rao', 'email' => 'a'.uniqid().'@test.com',
            'source' => 'Employee Referral', 'referred_by_id' => $referrer->id,
        ])->assertCreated();

        $candidate = HrCandidate::first();
        // Derived, never retyped — so a renamed employee cannot leave a stale name.
        $this->assertSame($referrer->id, (int) $candidate->referred_by_id);
        $this->assertSame('Ravi Kumar', $candidate->referred_by_name);
    }

    public function test_the_source_is_auto_set_for_an_employee_referral(): void
    {
        Sanctum::actingAs($this->actor);
        $referrer = $this->employee();

        // No source given — the referral itself is the source.
        $this->postJson('/api/hr/candidates', [
            'name' => 'Asha Rao', 'email' => 'a'.uniqid().'@test.com',
            'referred_by_id' => $referrer->id,
        ])->assertCreated();

        $this->assertSame('Employee Referral', HrCandidate::first()->source);
    }

    public function test_an_explicit_source_is_never_overwritten(): void
    {
        Sanctum::actingAs($this->actor);
        $referrer = $this->employee();

        $this->postJson('/api/hr/candidates', [
            'name' => 'Asha Rao', 'email' => 'a'.uniqid().'@test.com',
            'source' => 'Naukri', 'referred_by_id' => $referrer->id,
        ])->assertCreated();

        $this->assertSame('Naukri', HrCandidate::first()->source);
    }

    public function test_an_external_referrer_keeps_their_typed_name_and_no_id(): void
    {
        Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/candidates', [
            'name' => 'Asha Rao', 'email' => 'a'.uniqid().'@test.com',
            'source' => 'Direct', 'referred_by_name' => '  Meera Iyer (former colleague)  ',
        ])->assertCreated();

        $candidate = HrCandidate::first();
        $this->assertNull($candidate->referred_by_id);
        $this->assertSame('Meera Iyer (former colleague)', $candidate->referred_by_name);
    }

    public function test_a_referrer_from_another_tenant_is_refused(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $foreign = $this->employee('Other Tenant Person', 2);

        Sanctum::actingAs($this->actor);

        // The row exists, so an unscoped lookup would attach a foreign employee.
        $this->postJson('/api/hr/candidates', [
            'name' => 'Asha Rao', 'email' => 'a'.uniqid().'@test.com',
            'source' => 'Employee Referral', 'referred_by_id' => $foreign->id,
        ])->assertStatus(422);

        $this->assertSame(0, HrCandidate::count());
    }

    public function test_the_reference_is_optional_so_existing_callers_are_unaffected(): void
    {
        Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/candidates', [
            'name' => 'No Referral', 'email' => 'n'.uniqid().'@test.com', 'source' => 'LinkedIn',
        ])->assertCreated();

        $candidate = HrCandidate::first();
        $this->assertNull($candidate->referred_by_id);
        $this->assertNull($candidate->referred_by_name);
        $this->assertSame('LinkedIn', $candidate->source);
    }

    public function test_editing_a_reference_re_resolves_it(): void
    {
        Sanctum::actingAs($this->actor);
        $first  = $this->employee('First Referrer');
        $second = $this->employee('Second Referrer');

        $this->postJson('/api/hr/candidates', [
            'name' => 'Asha Rao', 'email' => 'a'.uniqid().'@test.com',
            'source' => 'Employee Referral', 'referred_by_id' => $first->id,
        ])->assertCreated();

        $candidate = HrCandidate::first();
        $this->putJson("/api/hr/candidates/{$candidate->id}", [
            'referred_by_id' => $second->id,
        ])->assertOk();

        // The name follows the id rather than being trusted from the client.
        $this->assertSame('Second Referrer', $candidate->fresh()->referred_by_name);
    }
}
