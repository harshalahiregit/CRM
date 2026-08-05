<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrOnboarding;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\OnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Review comment #17 — "Start onboarding – linked with candidate database, no
 * direct candidate entry".
 *
 * The rule being pinned: an onboarding cannot exist for somebody the candidate
 * database has never heard of. Before this, `candidate_id` was nullable while
 * `candidate_name` was a required free-text field, so a typed name was enough.
 */
class OnboardingFromCandidateTest extends TestCase
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

        // The welcome mail is a side effect of creating an onboarding, not the
        // subject of these tests.
        Mail::fake();
    }

    private function candidate(array $attrs = []): HrCandidate
    {
        return HrCandidate::create(array_merge([
            'tenant_id' => self::TENANT, 'name' => 'Asha Rao',
            'email' => 'c'.uniqid().'@test.com', 'stage' => 'Offer',
        ], $attrs));
    }

    public function test_starting_an_onboarding_without_a_candidate_is_refused(): void
    {
        Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/onboarding', [
            // Exactly the payload the old free-text form sent.
            'candidate_name' => 'Somebody Not In The Database',
            'position'       => 'Engineer',
            'joining_date'   => '2026-10-01',
        ])->assertStatus(422)->assertJsonValidationErrors('candidate_id');

        $this->assertSame(0, HrOnboarding::count());
    }

    public function test_a_candidate_from_another_tenant_is_refused(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $foreign = HrCandidate::create([
            'tenant_id' => 2, 'name' => 'Other Tenant', 'email' => 'o'.uniqid().'@test.com', 'stage' => 'Offer',
        ]);

        Sanctum::actingAs($this->actor);

        // The row EXISTS, so a bare `exists:hr_candidates,id` would admit it and
        // the insert would then die on the NOT NULL candidate_name — a 500 where
        // the honest answer is 422. Found by driving the live API, not by a unit
        // test, which is why this asserts the status code and not just the absence
        // of a record.
        $this->postJson('/api/hr/onboarding', [
            'candidate_id' => $foreign->id, 'position' => 'Engineer', 'joining_date' => '2026-10-01',
        ])->assertStatus(422)->assertJsonValidationErrors('candidate_id');

        $this->assertSame(0, HrOnboarding::count());
    }

    public function test_the_service_refuses_an_unresolvable_candidate_rather_than_hitting_the_database(): void
    {
        // Direct service callers bypass the form request, so the guard is repeated
        // there — otherwise this is a NOT NULL crash instead of a clear message.
        $this->expectException(\App\Exceptions\BusinessException::class);

        app(OnboardingService::class)->create([
            'candidate_id' => 999999, 'position' => 'QA', 'joining_date' => '2026-10-01',
        ], self::TENANT);
    }

    public function test_starting_from_a_candidate_succeeds_and_takes_their_name(): void
    {
        Sanctum::actingAs($this->actor);
        $candidate = $this->candidate(['name' => 'Asha Rao']);

        $this->postJson('/api/hr/onboarding', [
            'candidate_id' => $candidate->id,
            'position'     => 'Engineer',
            'joining_date' => '2026-10-01',
        ])->assertCreated();

        $record = HrOnboarding::first();
        // The name is DERIVED, so it can never disagree with the candidate.
        $this->assertSame($candidate->id, (int) $record->candidate_id);
        $this->assertSame('Asha Rao', $record->candidate_name);
    }

    public function test_an_explicit_name_is_still_accepted_for_the_existing_caller(): void
    {
        // OfferLetters.jsx already sends candidate_id AND candidate_name; that
        // call must keep working exactly as before.
        Sanctum::actingAs($this->actor);
        $candidate = $this->candidate(['name' => 'Asha Rao']);

        $this->postJson('/api/hr/onboarding', [
            'candidate_id'   => $candidate->id,
            'candidate_name' => 'Asha R.',
            'position'       => 'Engineer',
            'department'     => 'Engineering',
            'joining_date'   => '2026-10-01',
        ])->assertCreated();

        $this->assertSame('Asha R.', HrOnboarding::first()->candidate_name);
    }

    public function test_the_service_resolves_the_name_when_called_directly(): void
    {
        $candidate = $this->candidate(['name' => 'Direct Call']);

        $record = app(OnboardingService::class)->create([
            'candidate_id' => $candidate->id, 'position' => 'QA', 'joining_date' => '2026-10-01',
        ], self::TENANT);

        $this->assertSame('Direct Call', $record->candidate_name);
        $this->assertSame('Pending', $record->status);
    }
}
