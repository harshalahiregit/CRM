<?php

namespace Tests\Feature\Hr;

use App\Jobs\Hr\RecalculateCandidateScore;
use App\Models\Hr\AirScoringConfig;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrManpowerRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\CompanyPortalService;
use App\Services\Hr\Scoring\CandidateScoringEngine;
use App\Services\Hr\Scoring\RecommendationEngine;
use App\Services\Hr\Scoring\ScoreRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 5: one score endpoint, one vocabulary. The frontend derives no bands, so
 * this payload is the whole contract.
 */
class CandidateScoreEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // forceCreate: `id` is not fillable, so Tenant::create() would auto-assign and
        // tenant 999 (the cross-tenant fixture) would never exist for the users FK.
        foreach ([self::TENANT, 999] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'Tenant '.$id, 'slug' => 'tenant-'.$id,
                'subdomain' => 'tenant'.$id, 'status' => 'active',
            ])->save();
        }
    }

    private function actor(int $tenantId = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => 'HR', 'email' => 'hr'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function scorableCandidate(): HrCandidate
    {
        AirScoringConfig::create([
            'tenant_id' => self::TENANT, 'job_family' => 'Engineering', 'is_active' => true,
            'is_default' => true, 'min_confidence' => 0,
        ]);

        $mr = HrManpowerRequest::create([
            'tenant_id' => self::TENANT, 'department' => 'Engineering', 'position_title' => 'Dev',
            'number_of_posts' => 1, 'status' => 'Approved',
            'required_skills' => ['React'], 'experience_required' => '3 years',
        ]);
        $job = HrJobPosting::create([
            'tenant_id' => self::TENANT, 'title' => 'Dev', 'department' => 'Engineering',
            'location' => 'Bangalore', 'job_type' => 'Full-time', 'status' => 'Open',
            'requirements' => 'React', 'manpower_request_id' => $mr->id,
        ]);

        return HrCandidate::create([
            'tenant_id' => self::TENANT, 'name' => 'Scored Person', 'email' => 's'.uniqid().'@test.com',
            'stage' => 'Applied', 'job_posting_id' => $job->id,
            'skills' => ['React'], 'experience_years' => 5, 'location' => 'Bangalore',
        ]);
    }

    private function scoreIt(HrCandidate $c): void
    {
        (new RecalculateCandidateScore($c->id, self::TENANT, RecalculateCandidateScore::TRIGGER_MANUAL))
            ->handle(app(CandidateScoringEngine::class), app(ScoreRecorder::class));
    }

    public function test_endpoint_returns_the_full_score_payload(): void
    {
        $c = $this->scorableCandidate();
        $this->scoreIt($c);

        Sanctum::actingAs($this->actor());
        $res = $this->getJson("/api/hr/candidates/{$c->id}/score")->assertOk();

        $res->assertJsonStructure([
            'is_scored', 'overall_score', 'recommendation', 'confidence',
            'dimensions', 'strengths', 'weaknesses', 'risk_flags', 'summary', 'scored_at',
        ]);
        $this->assertTrue($res->json('is_scored'));
        $this->assertIsInt($res->json('overall_score'));
        $this->assertCount(10, $res->json('dimensions'));
        $this->assertContains($res->json('recommendation'), RecommendationEngine::ALL);
    }

    /** Never scored is a real state, not a 404 and not a zero. */
    public function test_endpoint_returns_an_unscored_payload_when_no_row_exists(): void
    {
        $c = HrCandidate::create([
            'tenant_id' => self::TENANT, 'name' => 'Unscored', 'email' => 'u@test.com', 'stage' => 'Applied',
        ]);

        Sanctum::actingAs($this->actor());
        $res = $this->getJson("/api/hr/candidates/{$c->id}/score")->assertOk();

        $this->assertFalse($res->json('is_scored'));
        $this->assertNull($res->json('overall_score'));
        $this->assertSame([], $res->json('dimensions'));
    }

    public function test_endpoint_never_recomputes_on_read(): void
    {
        $c = $this->scorableCandidate();
        $this->scoreIt($c);
        $before = $c->fresh()->updated_at;

        Sanctum::actingAs($this->actor());
        $this->getJson("/api/hr/candidates/{$c->id}/score")->assertOk();

        $this->assertEquals($before, $c->fresh()->updated_at, 'A page load must not trigger scoring');
        $this->assertSame(1, \App\Models\Hr\AirPredictionHistory::where('candidate_id', $c->id)->count());
    }

    public function test_endpoint_is_tenant_scoped(): void
    {
        $c = $this->scorableCandidate();
        $this->scoreIt($c);

        // 404, not 403 — the codebase hides the existence of another tenant's records
        // rather than confirming them, which is the convention assertTenant() applies
        // across HR and Compliance.
        Sanctum::actingAs($this->actor(999));
        $this->getJson("/api/hr/candidates/{$c->id}/score")->assertNotFound();
    }

    /** The fourth vocabulary is gone; the portal reports what the engine decided. */
    public function test_company_portal_reports_the_engine_recommendation(): void
    {
        $c = $this->scorableCandidate();
        $this->scoreIt($c);

        $evaluation = app(CompanyPortalService::class)->aiEvaluation($c->fresh());

        $this->assertSame($c->fresh()->ai_breakdown['recommendation'], $evaluation['recommendation']);
        $this->assertContains($evaluation['recommendation'], RecommendationEngine::ALL);
        $this->assertFalse(
            (new \ReflectionClass(CompanyPortalService::class))->hasMethod('recommendation'),
            'CompanyPortalService::recommendation() must be removed'
        );
    }

    /** The client portal serves the SAME payload as the HR side -- one contract. */
    public function test_company_portal_returns_the_shared_score_payload(): void
    {
        $c = $this->scorableCandidate();
        $this->scoreIt($c);

        $evaluation = app(CompanyPortalService::class)->aiEvaluation($c->fresh());

        Sanctum::actingAs($this->actor());
        $api = $this->getJson("/api/hr/candidates/{$c->id}/score")->json();

        $this->assertSame($api, $evaluation, 'Portal and HR must serve an identical payload');
        $this->assertArrayHasKey('dimensions', $evaluation);
        $this->assertNotEmpty($evaluation['dimensions']);

        foreach (['skill_match', 'experience_match', 'overall_fit', 'question_score', 'reasons'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $evaluation, "legacy key '{$legacy}' must be gone");
        }
    }

    /**
     * Pre-engine rows must not surface a score anywhere.
     *
     * 17 candidates still carry an ai_score written before the engine existed
     * (seeder literals). They have no `engine` stamp and no air_candidate_scores row,
     * so the score endpoint reports them unscored; every other payload must agree, or
     * a list shows 87% next to a profile saying "not scored".
     */
    public function test_pre_engine_scores_are_suppressed_everywhere(): void
    {
        $legacy = HrCandidate::create([
            'tenant_id' => self::TENANT, 'name' => 'Legacy', 'email' => 'l@test.com', 'stage' => 'Applied',
        ]);
        // Exactly what a seeded row looks like: a number, no engine stamp.
        $legacy->forceFill([
            'ai_score' => 87,
            'ai_breakdown' => ['skills_match' => 40, 'exp_match' => 85, 'overall_fit' => 63],
        ])->saveQuietly();
        $legacy = $legacy->fresh();

        $this->assertFalse($legacy->hasAiScreening(), 'no engine stamp = never screened');
        $this->assertFalse($legacy->isScored());
        $this->assertNull($legacy->publishedAiScore(), 'a pre-engine literal must never be published');

        Sanctum::actingAs($this->actor());
        $this->getJson("/api/hr/candidates/{$legacy->id}/score")
            ->assertOk()
            ->assertJson(['is_scored' => false, 'overall_score' => null]);

        $this->assertNull(app(CompanyPortalService::class)->aiEvaluation($legacy)['overall_score']);
    }

    /** And the Assessment gate must block it, since screening genuinely never ran. */
    public function test_pre_engine_row_cannot_enter_assessment(): void
    {
        $c = HrCandidate::create([
            'tenant_id' => self::TENANT, 'name' => 'Legacy2', 'email' => 'l2@test.com', 'stage' => 'Screening',
        ]);
        $c->forceFill(['ai_score' => 91, 'ai_breakdown' => ['overall_fit' => 70]])->saveQuietly();

        $this->expectException(\App\Exceptions\BusinessException::class);
        app(\App\Services\Hr\CandidateService::class)->updateStage($c->fresh(), 'Assessment');
    }
}
