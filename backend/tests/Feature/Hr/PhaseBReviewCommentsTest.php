<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrQuiz;
use App\Models\Hr\HrQuizAttempt;
use App\Models\Hr\HrQuizQuestion;
use App\Models\Hr\HrSurvey;
use App\Models\Hr\HrSurveyResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\QuizService;
use App\Services\Hr\SurveyReportService;
use App\Services\Hr\SurveyService;
use App\Services\Hr\TrainingProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase B of the original 45 review comments:
 *   #22 Training provider — department, expertise, certification, qualification, skills
 *   #23 Retraining count and history
 *   #25 Quiz with a question bank, multiple answers, pass marks and evaluation
 *   #26 Employee Survey
 */
class PhaseBReviewCommentsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, 999] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'T'.$id, 'slug' => 't'.$id, 'subdomain' => 't'.$id, 'status' => 'active',
            ])->save();
        }

        $this->actor = $this->hrUser();
        Sanctum::actingAs($this->actor);
    }

    private function hrUser(int $tenantId = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => 'HR', 'email' => 'hr'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function employee(array $attrs = []): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id' => self::TENANT, 'name' => 'Learner', 'employee_code' => 'LN-'.uniqid(),
            'department' => 'Engineering', 'designation' => 'Engineer', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ], $attrs));
    }

    /* ══ #22 — Training provider enhancements ════════════════════════ */

    public function test_a_provider_carries_department_expertise_certifications_qualifications_and_skills(): void
    {
        $dept = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Engineering']);

        $provider = app(TrainingProviderService::class)->create([
            'name' => 'Acme Training', 'provider_type' => 'External',
            'department_id'  => $dept->id,
            'expertise'      => ['Cloud', 'DevOps'],
            'certifications' => ['ISO 9001'],
            'qualifications' => ['MSc Computer Science'],
            'skills'         => ['Kubernetes', 'Terraform'],
        ], self::TENANT, $this->actor);

        $this->assertSame($dept->id, $provider['department_id']);
        $this->assertSame('Engineering', $provider['department_name'], 'reuses the existing department master');
        $this->assertSame(['Cloud', 'DevOps'], $provider['expertise']);
        $this->assertSame(['ISO 9001'], $provider['certifications']);
        $this->assertSame(['MSc Computer Science'], $provider['qualifications']);
        $this->assertSame(['Kubernetes', 'Terraform'], $provider['skills']);
    }

    public function test_provider_lists_are_de_duplicated_like_every_other_skill_list(): void
    {
        $provider = app(TrainingProviderService::class)->create([
            'name' => 'Dedup Co', 'skills' => ['PHP', 'php', '  PHP  ', 'React', ''],
        ], self::TENANT, $this->actor);

        $this->assertSame(['PHP', 'React'], $provider['skills']);
    }

    public function test_a_provider_list_can_be_cleared(): void
    {
        $provider = app(TrainingProviderService::class)->create([
            'name' => 'Clearable', 'expertise' => ['Cloud'],
        ], self::TENANT, $this->actor);

        // array_filter() would have dropped the empty array as if it were absent.
        $updated = app(TrainingProviderService::class)->update($provider['id'], ['expertise' => []], self::TENANT, $this->actor);

        $this->assertSame([], $updated['expertise']);
    }

    public function test_provider_endpoint_rejects_an_unknown_department(): void
    {
        $this->postJson('/api/hr/learning/providers', ['name' => 'Bad Dept', 'department_id' => 99999])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_a_department_from_another_tenant_is_rejected(): void
    {
        $foreign = HrDepartment::create(['tenant_id' => 999, 'name' => 'Foreign Dept']);

        // The id exists — just not for this tenant. A bare exists() rule would
        // have let it through and attached another tenant's department.
        $this->postJson('/api/hr/learning/providers', ['name' => 'Cross Tenant', 'department_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    /**
     * The Provider FORM sends all five fields over HTTP. The service-level tests
     * above prove the engine; this proves the wire — a payload shaped exactly like
     * the one the UI posts survives validation, persists, and comes back on the
     * list endpoint that the table renders from.
     */
    public function test_the_provider_endpoint_round_trips_all_five_fields(): void
    {
        $dept = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Safety']);

        $created = $this->postJson('/api/hr/learning/providers', [
            'name'           => 'NEBOSH Partners',
            'provider_type'  => 'External',
            'department_id'  => $dept->id,
            'expertise'      => ['Safety Training', 'Fire Drill'],
            'certifications' => ['ISO 45001'],
            'qualifications' => ['Certified NEBOSH Tutor'],
            'skills'         => ['Scaffolding', 'Rigging'],
        ])->assertCreated()
          ->assertJsonPath('department_id', $dept->id)
          ->assertJsonPath('department_name', 'Safety')
          ->assertJsonPath('expertise', ['Safety Training', 'Fire Drill'])
          ->assertJsonPath('certifications', ['ISO 45001'])
          ->assertJsonPath('qualifications', ['Certified NEBOSH Tutor'])
          ->assertJsonPath('skills', ['Scaffolding', 'Rigging'])
          ->json();

        // The list is what the table reads — the fields must survive that trip too,
        // or the UI shows a provider with no department and no expertise chips.
        $row = collect($this->getJson('/api/hr/learning/providers')->assertOk()->json('data'))
            ->firstWhere('id', $created['id']);

        $this->assertSame('Safety', $row['department_name']);
        $this->assertSame(['Safety Training', 'Fire Drill'], $row['expertise']);
        $this->assertSame(['Scaffolding', 'Rigging'], $row['skills']);
    }

    public function test_a_provider_department_can_be_cleared_from_the_form(): void
    {
        $dept = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Safety']);

        $created = $this->postJson('/api/hr/learning/providers', [
            'name' => 'Clearable', 'department_id' => $dept->id,
        ])->assertCreated()->json();

        // The form's "— None —" option sends null, not '' — an empty string would
        // fail the integer rule and the user could never undo a department.
        $this->putJson("/api/hr/learning/providers/{$created['id']}", [
            'name' => 'Clearable', 'department_id' => null,
        ])->assertOk()->assertJsonPath('department_id', null);
    }

    /* ══ #25 — Quiz: bank, multiple answers, pass marks, evaluation ══ */

    private function bankQuestion(array $overrides = []): array
    {
        return app(QuizService::class)->saveQuestion(null, array_merge([
            'question_text' => 'Which are programming languages?',
            'question_type' => HrQuizQuestion::MULTIPLE,
            'marks'         => 2,
            'options' => [
                ['option_text' => 'PHP',    'is_correct' => true],
                ['option_text' => 'React',  'is_correct' => true],
                ['option_text' => 'Coffee', 'is_correct' => false],
            ],
        ], $overrides), self::TENANT, $this->actor);
    }

    private function quizWith(array $questionIds, array $overrides = []): array
    {
        return app(QuizService::class)->saveQuiz(null, array_merge([
            'name' => 'Quiz '.uniqid(), 'pass_percentage' => 50,
            'questions' => array_map(fn ($id) => ['question_id' => $id], $questionIds),
        ], $overrides), self::TENANT, $this->actor);
    }

    public function test_a_question_needs_at_least_two_options(): void
    {
        $this->expectExceptionMessage('at least two options');
        app(QuizService::class)->saveQuestion(null, [
            'question_text' => 'One option?', 'options' => [['option_text' => 'Only', 'is_correct' => true]],
        ], self::TENANT, $this->actor);
    }

    public function test_a_question_needs_a_correct_answer(): void
    {
        // A question nobody can get right is a defect, not a hard question.
        $this->expectExceptionMessage('Mark at least one option');
        app(QuizService::class)->saveQuestion(null, [
            'question_text' => 'No key?',
            'options' => [['option_text' => 'A'], ['option_text' => 'B']],
        ], self::TENANT, $this->actor);
    }

    public function test_a_single_choice_question_cannot_have_two_correct_answers(): void
    {
        $this->expectExceptionMessage('only one correct answer');
        app(QuizService::class)->saveQuestion(null, [
            'question_text' => 'Pick one', 'question_type' => HrQuizQuestion::SINGLE,
            'options' => [
                ['option_text' => 'A', 'is_correct' => true],
                ['option_text' => 'B', 'is_correct' => true],
            ],
        ], self::TENANT, $this->actor);
    }

    public function test_a_quiz_totals_the_marks_of_its_bank_questions(): void
    {
        $a = $this->bankQuestion(['marks' => 2]);
        $b = $this->bankQuestion(['question_text' => 'Second', 'marks' => 3]);

        $quiz = $this->quizWith([$a['id'], $b['id']]);

        $this->assertEquals(5, $quiz['total_marks']);
        $this->assertCount(2, $quiz['questions']);
    }

    public function test_a_quiz_can_reweight_a_shared_bank_question(): void
    {
        $q = $this->bankQuestion(['marks' => 2]);

        $quiz = app(QuizService::class)->saveQuiz(null, [
            'name' => 'Weighted', 'questions' => [['question_id' => $q['id'], 'marks_override' => 10]],
        ], self::TENANT, $this->actor);

        // The bank entry is untouched — the same question is worth 2 elsewhere.
        $this->assertEquals(10, $quiz['total_marks']);
        $this->assertEquals(2, app(QuizService::class)->questions(self::TENANT)[0]['marks']);
    }

    public function test_the_paper_an_employee_sits_never_contains_the_answer_key(): void
    {
        $q = $this->bankQuestion();
        $quiz = $this->quizWith([$q['id']]);
        $employee = $this->employee();

        $paper = app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);

        foreach ($paper['questions'][0]['options'] as $option) {
            $this->assertArrayNotHasKey('is_correct', $option,
                'leaking the key would make the quiz pointless');
        }
    }

    public function test_an_exactly_correct_multiple_answer_scores_full_marks(): void
    {
        $q = $this->bankQuestion(['marks' => 2]);
        $quiz = $this->quizWith([$q['id']]);
        $employee = $this->employee();

        $paper = app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);
        $correct = collect(HrQuizQuestion::find($q['id'])->correctOptionIds())->all();

        $result = app(QuizService::class)->submitAttempt($paper['attempt_id'], [
            ['question_id' => $q['id'], 'selected_option_ids' => $correct],
        ], self::TENANT, $this->actor);

        $this->assertEquals(2, $result['obtained_marks']);
        $this->assertEquals(100, $result['percentage']);
        $this->assertTrue($result['passed']);
    }

    public function test_a_partially_correct_multiple_answer_scores_nothing(): void
    {
        $q = $this->bankQuestion(['marks' => 2]);
        $quiz = $this->quizWith([$q['id']]);
        $employee = $this->employee();

        $paper = app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);
        $correct = HrQuizQuestion::find($q['id'])->correctOptionIds();

        // One of the two right answers. No partial credit — see the service note.
        $result = app(QuizService::class)->submitAttempt($paper['attempt_id'], [
            ['question_id' => $q['id'], 'selected_option_ids' => [$correct[0]]],
        ], self::TENANT, $this->actor);

        $this->assertEquals(0, $result['obtained_marks']);
        $this->assertFalse($result['passed']);
    }

    public function test_selecting_every_option_scores_nothing(): void
    {
        $q = $this->bankQuestion(['marks' => 2]);
        $quiz = $this->quizWith([$q['id']]);
        $employee = $this->employee();

        $paper = app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);
        $all = HrQuizQuestion::find($q['id'])->options->pluck('id')->all();

        // The failure mode that makes a quiz worthless.
        $result = app(QuizService::class)->submitAttempt($paper['attempt_id'], [
            ['question_id' => $q['id'], 'selected_option_ids' => $all],
        ], self::TENANT, $this->actor);

        $this->assertEquals(0, $result['obtained_marks']);
    }

    public function test_the_pass_threshold_is_frozen_onto_the_attempt(): void
    {
        $q = $this->bankQuestion(['marks' => 2]);
        $quiz = $this->quizWith([$q['id']], ['pass_percentage' => 50]);
        $employee = $this->employee();

        $paper = app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);
        $result = app(QuizService::class)->submitAttempt($paper['attempt_id'], [
            ['question_id' => $q['id'], 'selected_option_ids' => HrQuizQuestion::find($q['id'])->correctOptionIds()],
        ], self::TENANT, $this->actor);

        $this->assertTrue($result['passed']);
        $this->assertEquals(50, $result['pass_percentage']);

        // Retuning the quiz must not retroactively fail an attempt already sat.
        HrQuiz::find($quiz['id'])->update(['pass_percentage' => 100]);
        $this->assertEquals(50, app(QuizService::class)->result($paper['attempt_id'], self::TENANT)['pass_percentage']);
    }

    public function test_starting_twice_resumes_the_open_attempt(): void
    {
        $q = $this->bankQuestion();
        $quiz = $this->quizWith([$q['id']]);
        $employee = $this->employee();

        $first  = app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);
        $second = app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);

        $this->assertSame($first['attempt_id'], $second['attempt_id'],
            'two open attempts would make "which one counts" unanswerable');
        $this->assertSame(1, HrQuizAttempt::count());
    }

    public function test_the_attempt_limit_is_enforced(): void
    {
        $q = $this->bankQuestion();
        $quiz = $this->quizWith([$q['id']], ['max_attempts' => 1]);
        $employee = $this->employee();

        $paper = app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);
        app(QuizService::class)->submitAttempt($paper['attempt_id'], [], self::TENANT, $this->actor);

        $this->expectExceptionMessage('at most 1 attempt');
        app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);
    }

    public function test_employee_quiz_history_reports_every_attempt(): void
    {
        $q = $this->bankQuestion(['marks' => 2]);
        $quiz = $this->quizWith([$q['id']]);
        $employee = $this->employee();
        $correct = HrQuizQuestion::find($q['id'])->correctOptionIds();

        $first = app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);
        app(QuizService::class)->submitAttempt($first['attempt_id'], [], self::TENANT, $this->actor);   // fail
        $second = app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);
        app(QuizService::class)->submitAttempt($second['attempt_id'], [
            ['question_id' => $q['id'], 'selected_option_ids' => $correct],
        ], self::TENANT, $this->actor);   // pass

        $history = app(QuizService::class)->employeeHistory($employee->id, self::TENANT);

        $this->assertSame(2, $history['total_attempts']);
        $this->assertSame(1, $history['passed_count']);
        $this->assertEquals(100, $history['best_percentage']);
        $this->assertSame(2, $history['attempts'][0]['attempt_number'], 'newest first');
    }

    public function test_a_question_that_has_been_answered_cannot_be_deleted(): void
    {
        $q = $this->bankQuestion();
        $quiz = $this->quizWith([$q['id']]);
        $employee = $this->employee();
        $paper = app(QuizService::class)->startAttempt($quiz['id'], $employee->id, self::TENANT);
        app(QuizService::class)->submitAttempt($paper['attempt_id'], [], self::TENANT, $this->actor);

        $this->expectExceptionMessage('Deactivate it instead');
        app(QuizService::class)->deleteQuestion($q['id'], self::TENANT, $this->actor);
    }

    /* ══ #26 — Employee Survey ═══════════════════════════════════════ */

    private function survey(array $overrides = [], array $questions = null): array
    {
        return app(SurveyService::class)->save(null, array_merge([
            'title' => 'Engagement '.uniqid(),
            'questions' => $questions ?? [
                ['question_text' => 'How satisfied are you?', 'question_type' => 'rating', 'rating_max' => 5, 'is_required' => true],
                ['question_text' => 'Any comments?', 'question_type' => 'text'],
                ['question_text' => 'Would you recommend us?', 'question_type' => 'boolean'],
                ['question_text' => 'Which benefits matter?', 'question_type' => 'multiple_choice',
                 'options' => ['Insurance', 'Leave', 'Learning']],
            ],
        ], $overrides), self::TENANT, $this->actor);
    }

    private function publish(int $id): array
    {
        return app(SurveyService::class)->publish($id, self::TENANT, $this->actor);
    }

    public function test_a_survey_supports_all_five_question_types(): void
    {
        $survey = $this->survey([], [
            ['question_text' => 'Text', 'question_type' => 'text'],
            ['question_text' => 'Rating', 'question_type' => 'rating', 'rating_max' => 5],
            ['question_text' => 'Single', 'question_type' => 'single_choice', 'options' => ['A', 'B']],
            ['question_text' => 'Multi', 'question_type' => 'multiple_choice', 'options' => ['A', 'B']],
            ['question_text' => 'YesNo', 'question_type' => 'boolean'],
        ]);

        $this->assertSame(
            ['text', 'rating', 'single_choice', 'multiple_choice', 'boolean'],
            array_column($survey['questions'], 'question_type')
        );
    }

    public function test_a_choice_question_needs_at_least_two_options(): void
    {
        $this->expectExceptionMessage('at least two options');
        $this->survey([], [['question_text' => 'Pick', 'question_type' => 'single_choice', 'options' => ['Only']]]);
    }

    public function test_a_survey_cannot_be_published_with_no_questions(): void
    {
        $survey = $this->survey([], []);

        $this->expectExceptionMessage('at least one question');
        $this->publish($survey['id']);
    }

    public function test_publishing_with_a_future_start_schedules_rather_than_opens(): void
    {
        $survey = $this->survey(['starts_at' => now()->addWeek()->toDateTimeString()]);

        $published = $this->publish($survey['id']);

        $this->assertSame(HrSurvey::SCHEDULED, $published['status']);
        $this->assertFalse($published['is_open']);
    }

    public function test_a_scheduled_survey_opens_itself_once_its_start_passes(): void
    {
        $survey = $this->survey(['starts_at' => now()->addDay()->toDateTimeString()]);
        $this->publish($survey['id']);

        // The date arrives.
        HrSurvey::find($survey['id'])->update(['starts_at' => now()->subMinute()]);
        app(SurveyService::class)->refreshStatuses(self::TENANT);

        $this->assertSame(HrSurvey::ACTIVE, HrSurvey::find($survey['id'])->status);
    }

    public function test_an_expired_survey_closes_itself(): void
    {
        $survey = $this->survey();
        $this->publish($survey['id']);
        HrSurvey::find($survey['id'])->update(['ends_at' => now()->subMinute()]);

        app(SurveyService::class)->refreshStatuses(self::TENANT);

        $this->assertSame(HrSurvey::CLOSED, HrSurvey::find($survey['id'])->status);
    }

    public function test_a_response_is_recorded_with_its_typed_answers(): void
    {
        $survey = $this->publish($this->survey()['id']);
        $employee = $this->employee();
        $questions = collect($survey['questions'])->keyBy('question_type');

        $result = app(SurveyService::class)->submitResponse($survey['id'], $employee->id, [
            ['question_id' => $questions['rating']['id'], 'answer_number' => 4],
            ['question_id' => $questions['text']['id'], 'answer_text' => 'All good'],
            ['question_id' => $questions['boolean']['id'], 'answer_boolean' => true],
            ['question_id' => $questions['multiple_choice']['id'], 'selected_options' => ['Leave', 'Learning']],
        ], self::TENANT);

        $this->assertFalse($result['anonymous']);
        $this->assertDatabaseCount('hr_survey_answers', 4);
    }

    public function test_a_required_question_must_be_answered(): void
    {
        $survey = $this->publish($this->survey()['id']);
        $employee = $this->employee();

        try {
            app(SurveyService::class)->submitResponse($survey['id'], $employee->id, [], self::TENANT);
            $this->fail('Expected the response to be refused.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('How satisfied are you?', $e->getMessage());
        }

        // Nothing partial may land in the table.
        $this->assertSame(0, HrSurveyResponse::count());
    }

    public function test_an_anonymous_response_stores_no_employee_id_at_all(): void
    {
        $survey = $this->publish($this->survey(['is_anonymous' => true])['id']);
        $employee = $this->employee();
        $rating = collect($survey['questions'])->firstWhere('question_type', 'rating');

        app(SurveyService::class)->submitResponse($survey['id'], $employee->id, [
            ['question_id' => $rating['id'], 'answer_number' => 5],
        ], self::TENANT);

        $response = HrSurveyResponse::first();

        // The whole anonymity guarantee — not a hash, not a token, nothing.
        $this->assertNull($response->employee_id);
        $this->assertTrue($response->isAnonymous());
        // Department IS kept, for the department breakdown the comment asks for.
        $this->assertSame('Engineering', $response->department);
    }

    public function test_an_anonymous_survey_cannot_restrict_to_one_response_each(): void
    {
        // It does not know who anyone is, so claiming to enforce it would be a lie.
        $survey = $this->survey(['is_anonymous' => true, 'allow_multiple_responses' => false]);

        $this->assertTrue($survey['allow_multiple_responses']);
    }

    public function test_a_named_survey_enforces_one_response_per_employee(): void
    {
        $survey = $this->publish($this->survey()['id']);
        $employee = $this->employee();
        $rating = collect($survey['questions'])->firstWhere('question_type', 'rating');
        $answers = [['question_id' => $rating['id'], 'answer_number' => 3]];

        app(SurveyService::class)->submitResponse($survey['id'], $employee->id, $answers, self::TENANT);

        $this->expectExceptionMessage('already responded');
        app(SurveyService::class)->submitResponse($survey['id'], $employee->id, $answers, self::TENANT);
    }

    public function test_anonymity_cannot_be_changed_once_responses_exist(): void
    {
        $survey = $this->publish($this->survey(['is_anonymous' => true])['id']);
        $employee = $this->employee();
        $rating = collect($survey['questions'])->firstWhere('question_type', 'rating');
        app(SurveyService::class)->submitResponse($survey['id'], $employee->id,
            [['question_id' => $rating['id'], 'answer_number' => 5]], self::TENANT);

        // Turning it off would expose people who answered believing otherwise.
        $this->expectExceptionMessage('Anonymity cannot be changed');
        app(SurveyService::class)->save($survey['id'], ['is_anonymous' => false], self::TENANT, $this->actor);
    }

    public function test_questions_are_locked_once_someone_has_answered(): void
    {
        $survey = $this->publish($this->survey()['id']);
        $employee = $this->employee();
        $rating = collect($survey['questions'])->firstWhere('question_type', 'rating');
        app(SurveyService::class)->submitResponse($survey['id'], $employee->id,
            [['question_id' => $rating['id'], 'answer_number' => 5]], self::TENANT);

        $this->expectExceptionMessage('can no longer be changed');
        app(SurveyService::class)->save($survey['id'], [
            'questions' => [['question_text' => 'New', 'question_type' => 'text']],
        ], self::TENANT, $this->actor);
    }

    public function test_a_department_survey_is_only_offered_to_that_department(): void
    {
        $eng = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Engineering']);
        $ops = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Operations']);

        $survey = $this->publish($this->survey(['audience' => 'Department', 'department_id' => $eng->id])['id']);

        $inside  = $this->employee(['department_id' => $eng->id]);
        $outside = $this->employee(['department_id' => $ops->id, 'employee_code' => 'OPS-1']);

        $this->assertCount(1, app(SurveyService::class)->availableFor($inside->id, self::TENANT));
        $this->assertCount(0, app(SurveyService::class)->availableFor($outside->id, self::TENANT));

        $this->expectExceptionMessage('not addressed to this employee');
        app(SurveyService::class)->submitResponse($survey['id'], $outside->id, [], self::TENANT);
    }

    public function test_analytics_average_ratings_and_report_the_distribution(): void
    {
        $survey = $this->publish($this->survey()['id']);
        $rating = collect($survey['questions'])->firstWhere('question_type', 'rating');

        foreach ([5, 3, 1] as $i => $score) {
            app(SurveyService::class)->submitResponse($survey['id'], $this->employee(['employee_code' => "R{$i}"])->id,
                [['question_id' => $rating['id'], 'answer_number' => $score]], self::TENANT);
        }

        $analytics = app(SurveyReportService::class)->analytics($survey['id'], self::TENANT);
        $q = collect($analytics['questions'])->firstWhere('question_id', $rating['id']);

        $this->assertSame(3, $analytics['response_count']);
        $this->assertEquals(3.0, $q['average']);
        // An average of 3 from all-3s is a different organisation from 5/3/1.
        $this->assertSame([1 => 1, 3 => 1, 5 => 1], $q['distribution']);
    }

    public function test_a_small_department_is_suppressed_on_an_anonymous_survey(): void
    {
        $survey = $this->publish($this->survey(['is_anonymous' => true])['id']);
        $rating = collect($survey['questions'])->firstWhere('question_type', 'rating');

        // Two responses from one department — below MIN_ANONYMOUS_GROUP.
        foreach ([4, 5] as $i => $score) {
            app(SurveyService::class)->submitResponse($survey['id'], $this->employee(['employee_code' => "S{$i}"])->id,
                [['question_id' => $rating['id'], 'answer_number' => $score]], self::TENANT);
        }

        $departments = app(SurveyReportService::class)->analytics($survey['id'], self::TENANT)['departments'];

        $this->assertTrue($departments[0]['suppressed']);
        $this->assertStringContainsString('anonymity', $departments[0]['reason']);
        // Listed, not omitted — omitting would itself reveal which groups are small.
        $this->assertSame(2, $departments[0]['response_count']);
    }

    public function test_a_named_survey_is_not_suppressed(): void
    {
        $survey = $this->publish($this->survey()['id']);
        $rating = collect($survey['questions'])->firstWhere('question_type', 'rating');
        app(SurveyService::class)->submitResponse($survey['id'], $this->employee()->id,
            [['question_id' => $rating['id'], 'answer_number' => 4]], self::TENANT);

        $departments = app(SurveyReportService::class)->analytics($survey['id'], self::TENANT)['departments'];

        $this->assertFalse($departments[0]['suppressed'], 'nothing was promised on a named survey');
    }

    public function test_the_response_list_omits_employee_columns_when_anonymous(): void
    {
        $survey = $this->publish($this->survey(['is_anonymous' => true])['id']);
        $rating = collect($survey['questions'])->firstWhere('question_type', 'rating');
        app(SurveyService::class)->submitResponse($survey['id'], $this->employee()->id,
            [['question_id' => $rating['id'], 'answer_number' => 4]], self::TENANT);

        $rows = app(SurveyReportService::class)->responses($survey['id'], self::TENANT);

        $this->assertArrayNotHasKey('employee_id', $rows[0]);
        $this->assertArrayNotHasKey('employee_name', $rows[0]);
    }

    public function test_export_produces_a_row_per_response_and_a_column_per_question(): void
    {
        $survey = $this->publish($this->survey()['id']);
        $rating = collect($survey['questions'])->firstWhere('question_type', 'rating');
        app(SurveyService::class)->submitResponse($survey['id'], $this->employee()->id,
            [['question_id' => $rating['id'], 'answer_number' => 4]], self::TENANT);

        $export = app(SurveyReportService::class)->exportRows($survey['id'], self::TENANT);

        $this->assertStringContainsString('.csv', $export['filename']);
        $this->assertCount(2, $export['rows'], 'header + one response');
        $this->assertContains('How satisfied are you?', $export['rows'][0]);
    }

    public function test_the_dashboard_counts_surveys_and_responses(): void
    {
        $survey = $this->publish($this->survey()['id']);
        $rating = collect($survey['questions'])->firstWhere('question_type', 'rating');
        app(SurveyService::class)->submitResponse($survey['id'], $this->employee()->id,
            [['question_id' => $rating['id'], 'answer_number' => 4]], self::TENANT);

        $dashboard = app(SurveyReportService::class)->dashboard(self::TENANT);

        $this->assertSame(1, $dashboard['total_surveys']);
        $this->assertSame(1, $dashboard['active_surveys']);
        $this->assertSame(1, $dashboard['total_responses']);
    }

    public function test_a_survey_with_responses_cannot_be_deleted(): void
    {
        $survey = $this->publish($this->survey()['id']);
        $rating = collect($survey['questions'])->firstWhere('question_type', 'rating');
        app(SurveyService::class)->submitResponse($survey['id'], $this->employee()->id,
            [['question_id' => $rating['id'], 'answer_number' => 4]], self::TENANT);

        $this->expectExceptionMessage('Close or archive it instead');
        app(SurveyService::class)->delete($survey['id'], self::TENANT, $this->actor);
    }

    /* ══ Tenancy + permissions ═══════════════════════════════════════ */

    public function test_one_tenant_cannot_read_another_tenants_survey(): void
    {
        $survey = $this->survey();

        Sanctum::actingAs($this->hrUser(999));

        $this->getJson("/api/hr/surveys/{$survey['id']}")->assertStatus(404);
        $this->getJson('/api/hr/surveys')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_survey_analytics_requires_hr_permission(): void
    {
        $survey = $this->survey();

        $viewer = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Viewer', 'email' => 'v'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]);
        Sanctum::actingAs($viewer);

        // Reading responses is reading what employees said.
        $this->getJson("/api/hr/surveys/{$survey['id']}/analytics")->assertStatus(403);
    }

    public function test_quiz_authoring_requires_hr_permission(): void
    {
        $viewer = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Viewer', 'email' => 'v'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/hr/learning/quiz/questions', [
            'question_text' => 'Sneaky', 'options' => [['option_text' => 'A', 'is_correct' => true], ['option_text' => 'B']],
        ])->assertStatus(403);
    }
}
