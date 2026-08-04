<?php

namespace Tests\Feature\Hr;

use App\Contracts\AI\AIProviderInterface;
use App\Exceptions\AIException;
use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrInterviewQuestion;
use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrInterviewRoundQuestion;
use App\Models\Hr\HrJobPosting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\InterviewQuestionAIService;
use App\Services\Hr\InterviewQuestionBankService;
use App\Services\Hr\InterviewQuestionSetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase D3 — the last two of the original 45 review comments:
 *   #10 Interview question bank + AI-generated questions
 *   #14 Highlight the next phase (frontend-only; see the note on the class)
 *
 * #14 adds no server behaviour: it derives every phase from statuses the API
 * already returns, which is exactly why there is no workflow engine to test here.
 * It is covered by the frontend build and by the resolver being pure.
 */
class PhaseD3ReviewCommentsTest extends TestCase
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

    private function bank(): InterviewQuestionBankService
    {
        return app(InterviewQuestionBankService::class);
    }

    private function sets(): InterviewQuestionSetService
    {
        return app(InterviewQuestionSetService::class);
    }

    private function question(array $attrs = []): array
    {
        return $this->bank()->save(array_merge([
            'question_text' => 'Explain the event loop.',
            'question_type' => 'technical',
            'difficulty'    => 'medium',
            'skills'        => 'JavaScript, Node',
            'marks'         => 5,
        ], $attrs), self::TENANT, $this->actor);
    }

    /* ── #10 — the bank ───────────────────────────────────────────────── */

    public function test_every_question_type_the_comment_lists_is_supported(): void
    {
        foreach (['mcq', 'subjective', 'coding', 'practical', 'behavioural', 'technical', 'hr'] as $type) {
            $this->assertContains($type, HrInterviewQuestion::TYPES);
        }
    }

    public function test_a_question_carries_every_facet(): void
    {
        $designation = HrDesignation::create([
            'tenant_id' => self::TENANT, 'name' => 'Senior Engineer', 'code' => 'SE', 'is_active' => true,
        ]);

        $q = $this->question([
            'category' => 'System Design', 'designation_id' => $designation->id,
            'tags' => 'backend, senior', 'difficulty' => 'hard',
            'experience_min' => 5, 'experience_max' => 10,
        ]);

        $this->assertSame('System Design', $q['category']);
        $this->assertSame('Senior Engineer', $q['designation']);
        $this->assertSame(['backend', 'senior'], $q['tags']);
        $this->assertSame('hard', $q['difficulty']);
        $this->assertEquals([5.0, 10.0], [$q['experience_min'], $q['experience_max']]);
        $this->assertNotEmpty($q['skills']);
    }

    public function test_an_mcq_supports_more_than_one_correct_answer(): void
    {
        $q = $this->question([
            'question_type' => 'mcq',
            'options' => [
                ['text' => 'A', 'is_correct' => true],
                ['text' => 'B', 'is_correct' => true],
                ['text' => 'C', 'is_correct' => false],
            ],
        ]);

        $this->assertCount(3, $q['options']);
        $this->assertSame(['A', 'B'], HrInterviewQuestion::find($q['id'])->correctOptions());
    }

    public function test_an_mcq_with_no_correct_option_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->question([
            'question_type' => 'mcq',
            'options' => [['text' => 'A', 'is_correct' => false], ['text' => 'B', 'is_correct' => false]],
        ]);
    }

    public function test_options_are_dropped_for_types_that_cannot_use_them(): void
    {
        // A coding question with an answer key would be meaningless — it is
        // scored by a person reading the code.
        $q = $this->question([
            'question_type' => 'coding',
            'options' => [['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => false]],
        ]);

        $this->assertSame([], $q['options']);
    }

    public function test_an_inverted_experience_range_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->question(['experience_min' => 8, 'experience_max' => 3]);
    }

    public function test_the_bank_filters_by_every_facet(): void
    {
        $designation = HrDesignation::create([
            'tenant_id' => self::TENANT, 'name' => 'QA', 'code' => 'QA', 'is_active' => true,
        ]);
        $this->question(['question_text' => 'Hard React question', 'difficulty' => 'hard',
            'skills' => 'React', 'question_type' => 'technical', 'experience_min' => 5, 'experience_max' => 9]);
        $this->question(['question_text' => 'Easy HR question', 'difficulty' => 'easy',
            'skills' => 'Communication', 'question_type' => 'hr', 'designation_id' => $designation->id]);

        $this->assertCount(1, $this->bank()->list(self::TENANT, ['difficulty' => 'hard']));
        $this->assertCount(1, $this->bank()->list(self::TENANT, ['question_type' => 'hr']));
        $this->assertCount(1, $this->bank()->list(self::TENANT, ['designation_id' => $designation->id]));
        $this->assertCount(1, $this->bank()->list(self::TENANT, ['skills' => 'React']));
        $this->assertCount(1, $this->bank()->list(self::TENANT, ['search' => 'Easy HR']));
        // Experience 7 falls inside 5–9 and the other question has no band at all.
        $this->assertCount(2, $this->bank()->list(self::TENANT, ['experience' => 7]));
        $this->assertCount(1, $this->bank()->list(self::TENANT, ['experience' => 2]));
    }

    public function test_skill_search_does_not_match_a_substring_of_another_skill(): void
    {
        $this->question(['question_text' => 'JS question', 'skills' => 'JavaScript']);

        // "java" must not match "javascript" — the exact bug a LIKE would cause.
        $this->assertCount(0, $this->bank()->list(self::TENANT, ['skills' => 'Java']));
        $this->assertCount(1, $this->bank()->list(self::TENANT, ['skills' => 'JavaScript']));
    }

    public function test_a_question_can_be_deactivated_and_filtered_out(): void
    {
        $q = $this->question();
        $this->bank()->toggle($q['id'], self::TENANT, $this->actor);

        $this->assertCount(0, $this->bank()->list(self::TENANT, ['is_active' => '1']));
        $this->assertCount(1, $this->bank()->list(self::TENANT, ['is_active' => '0']));
    }

    /* ── #10 — AI generation ──────────────────────────────────────────── */

    /** A stub provider, so no test ever makes a real API call. */
    private function fakeAi(string $response): void
    {
        $this->app->bind(AIProviderInterface::class, fn () => new class($response) implements AIProviderInterface
        {
            public function __construct(private string $response)
            {
            }

            public function complete(string $prompt, array $options = []): string
            {
                return $this->response;
            }

            public function name(): string
            {
                return 'stub';
            }

            public function model(): string
            {
                return 'stub-1';
            }
        });
    }

    public function test_generation_reuses_the_shared_ai_provider(): void
    {
        $this->fakeAi(json_encode(['questions' => [
            ['question_text' => 'Describe a memory leak you fixed.', 'question_type' => 'technical',
             'difficulty' => 'hard', 'expected_answer' => 'Names the tooling and the fix.', 'marks' => 5],
        ]]));

        $out = app(InterviewQuestionAIService::class)->generate(
            ['designation' => 'Backend Engineer', 'count' => 1], self::TENANT, $this->actor
        );

        $this->assertCount(1, $out['questions']);
        // Provenance comes from the shared provider, not a second abstraction.
        $this->assertSame('stub', $out['meta']['provider']);
        $this->assertSame('stub-1', $out['meta']['model']);
        $this->assertSame('ai', $out['questions'][0]['source']);
    }

    public function test_generation_stores_the_inputs_it_was_given(): void
    {
        $this->fakeAi(json_encode(['questions' => [['question_text' => 'Q', 'question_type' => 'hr']]]));

        $out = app(InterviewQuestionAIService::class)->generate([
            'designation' => 'QA Lead', 'skills' => ['Selenium'], 'difficulty' => 'hard', 'count' => 1,
        ], self::TENANT, $this->actor);

        // "Why did it ask that?" is answerable only if we kept what it was told.
        // SkillMatcher::clean de-duplicates but PRESERVES the spelling given.
        $this->assertSame('QA Lead', $out['meta']['inputs']['designation']);
        $this->assertSame(['Selenium'], $out['meta']['inputs']['skills']);
        $this->assertSame('hard', $out['meta']['inputs']['difficulty']);
    }

    public function test_generation_reads_a_job_posting_rather_than_asking_again(): void
    {
        // Skills and experience live on the REQUISITION, not the posting —
        // hr_job_postings has neither column.
        $mr = \App\Models\Hr\HrManpowerRequest::create([
            'tenant_id' => self::TENANT, 'department' => 'Engineering',
            'position_title' => 'React Developer', 'position' => 'React Developer',
            'number_of_positions' => 1, 'status' => 'Job_Posted',
            'requested_by' => $this->actor->id,
            'required_skills' => ['React', 'TypeScript'], 'experience_required' => '3-5 years',
        ]);

        $job = HrJobPosting::create([
            'tenant_id' => self::TENANT, 'manpower_request_id' => $mr->id,
            'title' => 'React Developer', 'department' => 'Engineering',
            'location' => 'Pune', 'status' => 'Published', 'number_of_openings' => 1,
            'description' => 'Build the web client.',
        ]);
        $this->fakeAi(json_encode(['questions' => [['question_text' => 'Q', 'question_type' => 'technical']]]));

        $out = app(InterviewQuestionAIService::class)->generate(
            ['job_posting_id' => $job->id, 'count' => 1], self::TENANT, $this->actor
        );

        // The band is parsed out of the posting's free-text experience field.
        $this->assertSame(3.0, $out['questions'][0]['experience_min']);
        $this->assertSame(5.0, $out['questions'][0]['experience_max']);
    }

    public function test_generation_with_nothing_to_go_on_is_refused(): void
    {
        $this->fakeAi('{}');

        $this->expectException(BusinessException::class);
        app(InterviewQuestionAIService::class)->generate(['count' => 3], self::TENANT, $this->actor);
    }

    public function test_a_fenced_json_response_is_still_read(): void
    {
        // Models wrap JSON in a fence despite being told not to.
        $this->fakeAi("Sure!\n```json\n".json_encode(['questions' => [
            ['question_text' => 'Q1', 'question_type' => 'behavioural'],
        ]])."\n```");

        $out = app(InterviewQuestionAIService::class)->generate(
            ['designation' => 'Manager', 'count' => 1], self::TENANT, $this->actor
        );

        $this->assertSame('Q1', $out['questions'][0]['question_text']);
    }

    public function test_an_unscorable_generated_mcq_becomes_subjective_not_rubbish(): void
    {
        $this->fakeAi(json_encode(['questions' => [
            ['question_text' => 'Pick one', 'question_type' => 'mcq',
             'options' => [['text' => 'A', 'is_correct' => false]]],
        ]]));

        $out = app(InterviewQuestionAIService::class)->generate(
            ['designation' => 'Dev', 'count' => 1], self::TENANT, $this->actor
        );

        // The question is usually still worth asking — just not as an MCQ.
        $this->assertSame('subjective', $out['questions'][0]['question_type']);
        $this->assertSame([], $out['questions'][0]['options']);
    }

    public function test_an_unreadable_ai_response_is_a_clear_error(): void
    {
        $this->fakeAi('I am afraid I cannot do that.');

        $this->expectException(BusinessException::class);
        app(InterviewQuestionAIService::class)->generate(
            ['designation' => 'Dev', 'count' => 1], self::TENANT, $this->actor
        );
    }

    public function test_a_provider_outage_surfaces_as_unavailable_not_a_crash(): void
    {
        $this->app->bind(AIProviderInterface::class, fn () => new class implements AIProviderInterface
        {
            public function complete(string $prompt, array $options = []): string
            {
                throw new AIException('rate limited');
            }

            public function name(): string
            {
                return 'stub';
            }

            public function model(): string
            {
                return 'stub-1';
            }
        });

        $this->expectException(BusinessException::class);
        app(InterviewQuestionAIService::class)->generate(
            ['designation' => 'Dev', 'count' => 1], self::TENANT, $this->actor
        );
    }

    public function test_generation_writes_nothing_until_the_drafts_are_saved(): void
    {
        $this->fakeAi(json_encode(['questions' => [['question_text' => 'Draft', 'question_type' => 'hr']]]));

        $out = app(InterviewQuestionAIService::class)->generate(
            ['designation' => 'Dev', 'count' => 1], self::TENANT, $this->actor
        );

        // Regenerate must be free of side effects, which it only is if nothing
        // was written the first time.
        $this->assertSame(0, HrInterviewQuestion::count());

        $this->bank()->saveMany($out['questions'], self::TENANT, $this->actor);
        $this->assertSame(1, HrInterviewQuestion::count());
        $this->assertSame('ai', HrInterviewQuestion::first()->source);
        $this->assertSame('stub', HrInterviewQuestion::first()->ai_meta['provider']);
    }

    /* ── #10 — sets and round integration ─────────────────────────────── */

    private function round(): HrInterviewRound
    {
        $candidate = HrCandidate::create([
            'tenant_id' => self::TENANT, 'name' => 'Cand', 'email' => 'c'.uniqid().'@test.com',
            'stage' => 'Interview',
        ]);

        return HrInterviewRound::create([
            'tenant_id' => self::TENANT, 'candidate_id' => $candidate->id,
            'round_name' => 'Technical 1', 'status' => 'Scheduled',
        ]);
    }

    public function test_a_set_groups_bank_questions_without_copying_them(): void
    {
        $a = $this->question(['question_text' => 'A']);
        $b = $this->question(['question_text' => 'B']);

        $set = $this->sets()->saveSet([
            'name' => 'Technical R1', 'question_ids' => [$a['id'], $b['id']],
        ], self::TENANT, $this->actor);

        $this->assertSame(2, $set['question_count']);
        // The bank still holds exactly two questions — the set references them.
        $this->assertSame(2, HrInterviewQuestion::count());
    }

    public function test_questions_attach_to_a_round_from_a_set(): void
    {
        $q = $this->question();
        $set = $this->sets()->saveSet(['name' => 'S', 'question_ids' => [$q['id']]], self::TENANT, $this->actor);
        $round = $this->round();

        $out = $this->sets()->attach($round, ['set_id' => $set['id']], self::TENANT, $this->actor);

        $this->assertCount(1, $out['questions']);
        $this->assertSame('manual', $out['questions'][0]['selection_mode']);
    }

    public function test_random_selection_draws_only_active_questions(): void
    {
        foreach (range(1, 5) as $i) {
            $this->question(['question_text' => "Q{$i}"]);
        }
        $retired = $this->question(['question_text' => 'Retired']);
        $this->bank()->toggle($retired['id'], self::TENANT, $this->actor);

        $out = $this->sets()->attach($this->round(), ['random' => ['count' => 10]], self::TENANT, $this->actor);

        $this->assertCount(5, $out['questions']);
        $this->assertSame('random', $out['questions'][0]['selection_mode']);
        $this->assertNotContains('Retired', array_column($out['questions'], 'question_text'));
    }

    public function test_random_selection_honours_the_filters(): void
    {
        $this->question(['question_text' => 'Hard', 'difficulty' => 'hard']);
        $this->question(['question_text' => 'Easy', 'difficulty' => 'easy']);

        $out = $this->sets()->attach($this->round(),
            ['random' => ['count' => 5, 'difficulty' => 'hard']], self::TENANT, $this->actor);

        $this->assertCount(1, $out['questions']);
        $this->assertSame('Hard', $out['questions'][0]['question_text']);
    }

    public function test_criteria_that_match_nothing_are_refused_rather_than_silently_empty(): void
    {
        $this->question(['difficulty' => 'easy']);

        $this->expectException(BusinessException::class);
        $this->sets()->attach($this->round(), ['random' => ['count' => 3, 'difficulty' => 'expert']],
            self::TENANT, $this->actor);
    }

    public function test_the_asked_question_text_is_snapshotted(): void
    {
        $q = $this->question(['question_text' => 'Original wording']);
        $round = $this->round();
        $this->sets()->attach($round, ['question_ids' => [$q['id']]], self::TENANT, $this->actor);

        // Editing the bank later must not rewrite what was actually asked.
        $this->bank()->save(['id' => $q['id'], 'question_text' => 'Reworded later',
            'question_type' => 'technical'], self::TENANT, $this->actor);

        $out = $this->sets()->forRound($round->fresh(), self::TENANT);
        $this->assertSame('Original wording', $out['questions'][0]['question_text']);
    }

    public function test_attaching_twice_does_not_wipe_an_evaluation(): void
    {
        $q = $this->question();
        $round = $this->round();
        $out = $this->sets()->attach($round, ['question_ids' => [$q['id']]], self::TENANT, $this->actor);

        $this->sets()->evaluate($round, [['id' => $out['questions'][0]['id'], 'score' => 4]], self::TENANT, $this->actor);
        $again = $this->sets()->attach($round, ['question_ids' => [$q['id']]], self::TENANT, $this->actor);

        $this->assertCount(1, $again['questions']);
        $this->assertEquals(4, $again['questions'][0]['score']);
    }

    public function test_an_interviewer_scores_a_question(): void
    {
        $q = $this->question(['marks' => 10]);
        $round = $this->round();
        $out = $this->sets()->attach($round, ['question_ids' => [$q['id']]], self::TENANT, $this->actor);

        $result = $this->sets()->evaluate($round, [[
            'id' => $out['questions'][0]['id'], 'score' => 7, 'answer_notes' => 'Solid, missed edge cases',
        ]], self::TENANT, $this->actor);

        $this->assertEquals(7, $result['questions'][0]['score']);
        $this->assertSame('Solid, missed edge cases', $result['questions'][0]['answer_notes']);
        $this->assertEquals(70.0, $result['summary']['percent']);
    }

    public function test_a_score_above_the_marks_available_is_capped(): void
    {
        $q = $this->question(['marks' => 5]);
        $round = $this->round();
        $out = $this->sets()->attach($round, ['question_ids' => [$q['id']]], self::TENANT, $this->actor);

        $result = $this->sets()->evaluate($round, [['id' => $out['questions'][0]['id'], 'score' => 99]],
            self::TENANT, $this->actor);

        // An uncapped score would corrupt the round percentage.
        $this->assertEquals(5, $result['questions'][0]['score']);
    }

    public function test_an_mcq_scores_itself_from_the_answer_key(): void
    {
        $q = $this->question([
            'question_type' => 'mcq', 'marks' => 4,
            'options' => [
                ['text' => 'A', 'is_correct' => true],
                ['text' => 'B', 'is_correct' => true],
                ['text' => 'C', 'is_correct' => false],
            ],
        ]);
        $round = $this->round();
        $out = $this->sets()->attach($round, ['question_ids' => [$q['id']]], self::TENANT, $this->actor);
        $rqId = $out['questions'][0]['id'];

        $partial = $this->sets()->evaluate($round, [['id' => $rqId, 'selected_options' => ['A']]],
            self::TENANT, $this->actor);
        // Half of a multi-correct answer is not a correct answer.
        $this->assertFalse($partial['questions'][0]['is_correct']);
        $this->assertEquals(0, $partial['questions'][0]['score']);

        $full = $this->sets()->evaluate($round, [['id' => $rqId, 'selected_options' => ['A', 'B']]],
            self::TENANT, $this->actor);
        $this->assertTrue($full['questions'][0]['is_correct']);
        $this->assertEquals(4, $full['questions'][0]['score']);
    }

    public function test_an_interviewer_score_beats_the_mcq_answer_key(): void
    {
        $q = $this->question([
            'question_type' => 'mcq', 'marks' => 4,
            'options' => [['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => false]],
        ]);
        $round = $this->round();
        $out = $this->sets()->attach($round, ['question_ids' => [$q['id']]], self::TENANT, $this->actor);

        $result = $this->sets()->evaluate($round, [[
            'id' => $out['questions'][0]['id'], 'selected_options' => ['B'], 'score' => 2,
        ]], self::TENANT, $this->actor);

        // They explained their reasoning well — the human's judgement stands.
        $this->assertEquals(2, $result['questions'][0]['score']);
        $this->assertFalse($result['questions'][0]['is_correct']);
    }

    public function test_the_percentage_covers_only_what_has_been_scored(): void
    {
        $a = $this->question(['question_text' => 'A', 'marks' => 10]);
        $b = $this->question(['question_text' => 'B', 'marks' => 10]);
        $round = $this->round();
        $out = $this->sets()->attach($round, ['question_ids' => [$a['id'], $b['id']]], self::TENANT, $this->actor);

        $result = $this->sets()->evaluate($round, [['id' => $out['questions'][0]['id'], 'score' => 8]],
            self::TENANT, $this->actor);

        // 8/10, not 8/20 — a half-finished evaluation must not read as a low one.
        $this->assertEquals(80.0, $result['summary']['percent']);
        $this->assertSame(1, $result['summary']['evaluated']);
        $this->assertSame(2, $result['summary']['total']);
    }

    public function test_a_round_with_no_questions_still_works(): void
    {
        // Backward compatibility: every round that existed before #10 has none.
        $out = $this->sets()->forRound($this->round(), self::TENANT);

        $this->assertSame([], $out['questions']);
        $this->assertSame(0, $out['summary']['total']);
        $this->assertNull($out['summary']['percent']);
    }

    public function test_a_question_already_asked_is_retired_not_deleted(): void
    {
        $q = $this->question();
        $round = $this->round();
        $this->sets()->attach($round, ['question_ids' => [$q['id']]], self::TENANT, $this->actor);

        $this->bank()->destroy($q['id'], self::TENANT, $this->actor);

        // Deleting would cascade the evaluation away with it.
        $this->assertNotNull(HrInterviewQuestion::find($q['id']));
        $this->assertFalse(HrInterviewQuestion::find($q['id'])->is_active);
        $this->assertSame(1, HrInterviewRoundQuestion::count());
    }

    public function test_an_unasked_question_is_deleted_outright(): void
    {
        $q = $this->question();
        $this->bank()->destroy($q['id'], self::TENANT, $this->actor);

        $this->assertNull(HrInterviewQuestion::find($q['id']));
    }

    /* ── Permissions + endpoints ──────────────────────────────────────── */

    public function test_the_bank_endpoints_respond(): void
    {
        Sanctum::actingAs($this->actor);
        $this->question();

        $this->getJson('/api/hr/interview-questions')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/hr/interview-questions/meta')->assertOk()
            ->assertJsonStructure(['types', 'difficulties', 'categories']);
    }

    public function test_authoring_requires_hr_permission_but_reading_a_round_does_not(): void
    {
        $q = $this->question();
        $round = $this->round();
        $this->sets()->attach($round, ['question_ids' => [$q['id']]], self::TENANT, $this->actor);

        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Interviewer', 'email' => 'i'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]));

        $this->postJson('/api/hr/interview-questions', ['question_text' => 'Sneaky'])->assertForbidden();
        // …but the interviewer running the round must be able to see and score it.
        $this->getJson("/api/hr/interviews/{$round->id}/questions")->assertOk();
    }

    public function test_one_tenant_cannot_reach_another_tenants_questions(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $this->question();

        $this->assertCount(0, $this->bank()->list(2));
    }
}
