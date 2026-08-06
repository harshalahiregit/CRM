<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrManpowerRequest;
use App\Models\Hr\HrOffer;
use App\Models\Hr\HrOnboarding;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\CandidateService;
use App\Services\Hr\InterviewService;
use App\Services\Hr\JobPostingService;
use App\Services\Hr\OfferService;
use App\Services\Hr\OnboardingService;
use App\Services\Hr\ManpowerRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review comment #3 — "Filter option in every listing. Ex. HIRING MANAGER".
 *
 * Six listings, one definition of "belongs to this hiring manager". These tests
 * pin BOTH halves of that: the filter narrows correctly at every distance from
 * the requisition, and — just as important — an absent filter still returns
 * everything, because a filter bar that silently empties a screen is worse than
 * no filter at all.
 */
class HiringManagerFilterTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $actor;

    private User $mgrA;

    private User $mgrB;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->actor = $this->user('admin');
        $this->mgrA  = $this->user('staff', 'department_head');
        $this->mgrB  = $this->user('staff', 'department_head');
    }

    private function user(string $role, ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'email' => $role.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => $role, 'status' => 'active', 'internal_role' => $internal,
        ]);
    }

    /**
     * One full recruitment chain owned by the given manager:
     * requisition → job → candidate → interview + offer + onboarding.
     */
    private function chain(?User $manager, string $label): array
    {
        $mr = HrManpowerRequest::create([
            'tenant_id' => self::TENANT, 'department' => 'Engineering',
            'position_title' => $label, 'position' => $label, 'number_of_positions' => 1,
            'status' => 'Job_Posted', 'requested_by' => $this->actor->id,
            'hiring_manager_id' => $manager?->id,
        ]);

        $job = HrJobPosting::create([
            'tenant_id' => self::TENANT, 'manpower_request_id' => $mr->id,
            'title' => $label, 'department' => 'Engineering', 'location' => 'Pune',
            'status' => 'Published', 'number_of_openings' => 1,
        ]);

        $candidate = HrCandidate::create([
            'tenant_id' => self::TENANT, 'job_posting_id' => $job->id,
            'name' => $label.' Candidate', 'email' => uniqid().'@test.com', 'stage' => 'Interview',
        ]);

        $interview = HrInterviewRound::create([
            'tenant_id' => self::TENANT, 'candidate_id' => $candidate->id,
            'round_name' => $label.' Round', 'status' => 'Scheduled',
        ]);

        $offer = HrOffer::create([
            'tenant_id' => self::TENANT, 'candidate_id' => $candidate->id,
            'position' => $label, 'department' => 'Engineering',
            'offered_ctc' => 800000, 'joining_date' => '2026-10-01', 'status' => 'Draft',
        ]);

        $onboarding = HrOnboarding::create([
            'tenant_id' => self::TENANT, 'candidate_id' => $candidate->id,
            'candidate_name' => $label.' Candidate', 'position' => $label,
            'joining_date' => '2026-10-01', 'status' => 'Pending',
        ]);

        return compact('mr', 'job', 'candidate', 'interview', 'offer', 'onboarding');
    }

    /** Every listing, as (label, callable(array $filters): int count). */
    private function listings(): array
    {
        return [
            'manpower requests' => fn ($f) => app(ManpowerRequestService::class)->list($this->actor, $f)->count(),
            'job postings'      => fn ($f) => app(JobPostingService::class)->list($this->actor, $f)->count(),
            'candidates'        => fn ($f) => app(CandidateService::class)->list(self::TENANT, $f)->count(),
            'interviews'        => fn ($f) => app(InterviewService::class)->list(self::TENANT, $f)->count(),
            'offers'            => fn ($f) => app(OfferService::class)->list(self::TENANT, $f)->count(),
            'onboarding'        => fn ($f) => app(OnboardingService::class)->list(self::TENANT, $f)->count(),
        ];
    }

    public function test_every_listing_filters_by_hiring_manager(): void
    {
        $this->chain($this->mgrA, 'Alpha');
        $this->chain($this->mgrB, 'Beta');

        foreach ($this->listings() as $name => $count) {
            $this->assertSame(2, $count([]), "{$name}: unfiltered must return both");
            $this->assertSame(1, $count(['hiring_manager_id' => $this->mgrA->id]),
                "{$name}: must return only manager A's record");
            $this->assertSame(1, $count(['hiring_manager_id' => $this->mgrB->id]),
                "{$name}: must return only manager B's record");
        }
    }

    public function test_an_absent_or_all_filter_never_narrows_a_listing(): void
    {
        $this->chain($this->mgrA, 'Alpha');
        $this->chain($this->mgrB, 'Beta');

        // 'All' is what the filter bars send for "no filter"; '' and null arrive
        // from a cleared control. Treating any of them as an id would empty a
        // screen the user never filtered.
        foreach ($this->listings() as $name => $count) {
            foreach ([[], ['hiring_manager_id' => 'All'], ['hiring_manager_id' => ''], ['hiring_manager_id' => null]] as $filters) {
                $this->assertSame(2, $count($filters), "{$name}: must not narrow on ".json_encode($filters));
            }
        }
    }

    public function test_records_with_no_hiring_manager_are_excluded_when_filtering(): void
    {
        $this->chain($this->mgrA, 'Alpha');
        $this->chain(null, 'Unassigned');   // requisition with no hiring manager

        foreach ($this->listings() as $name => $count) {
            $this->assertSame(2, $count([]), "{$name}: both exist");
            $this->assertSame(1, $count(['hiring_manager_id' => $this->mgrA->id]),
                "{$name}: an unassigned record must not match a specific manager");
        }
    }

    public function test_a_manager_with_nothing_assigned_gets_an_empty_list_not_everything(): void
    {
        $this->chain($this->mgrA, 'Alpha');
        $stranger = $this->user('staff', 'department_head');

        foreach ($this->listings() as $name => $count) {
            $this->assertSame(0, $count(['hiring_manager_id' => $stranger->id]),
                "{$name}: an unmatched manager must return nothing, not fall through to all");
        }
    }

    public function test_a_candidate_with_no_job_posting_is_excluded_rather_than_crashing(): void
    {
        // Direct/walk-in sourcing: no job posting, so no requisition and no
        // hiring manager to inherit. whereHas on a null relation must simply
        // not match.
        HrCandidate::create([
            'tenant_id' => self::TENANT, 'name' => 'Walk In',
            'email' => uniqid().'@test.com', 'stage' => 'Applied',
        ]);
        $this->chain($this->mgrA, 'Alpha');

        $this->assertSame(2, app(CandidateService::class)->list(self::TENANT, [])->count());
        $this->assertSame(1, app(CandidateService::class)
            ->list(self::TENANT, ['hiring_manager_id' => $this->mgrA->id])->count());
    }

    public function test_the_endpoints_accept_the_filter(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->actor);
        $this->chain($this->mgrA, 'Alpha');
        $this->chain($this->mgrB, 'Beta');

        foreach (['/api/hr/candidates', '/api/hr/interviews', '/api/hr/offers', '/api/hr/onboarding'] as $url) {
            $all = $this->getJson($url)->assertOk()->json();
            $one = $this->getJson($url.'?hiring_manager_id='.$this->mgrA->id)->assertOk()->json();

            $this->assertSame(2, $this->rowCount($all), "{$url}: unfiltered");
            $this->assertSame(1, $this->rowCount($one), "{$url}: filtered — the controller must pass it through");
        }
    }

    /** Endpoints differ in envelope; count whichever shape came back. */
    private function rowCount($payload): int
    {
        if (is_array($payload) && array_key_exists('data', $payload) && is_array($payload['data'])) {
            return count($payload['data']);
        }

        return is_array($payload) ? count($payload) : 0;
    }
}
