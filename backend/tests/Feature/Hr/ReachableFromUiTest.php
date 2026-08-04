<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrQuizQuestion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The endpoints behind #25, #38 and #43 all had complete services and green
 * service-level tests while being unreachable from the product: no screen called
 * them. Those tests passed by constructing state through the service layer or by
 * seeding models directly, which is exactly why they never caught it.
 *
 * These tests drive the same HTTP routes the new screens use, with the payload
 * shapes those screens send. They fail if a route, a validation rule or a
 * response key the UI depends on moves.
 */
class ReachableFromUiTest extends TestCase
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

        Sanctum::actingAs($this->actor);
    }

    private function employee(array $attrs = []): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id' => self::TENANT, 'name' => 'Asha Rao', 'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Engineering', 'designation' => 'Engineer',
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ], $attrs));
    }

    /* ══ #43 — employee skills, enter / save / display / score ══════════ */

    public function test_an_employee_skill_list_can_be_saved_and_read_back(): void
    {
        $employee = $this->employee();

        // The panel's save: PUT the whole list, as TagInput produces it.
        $this->putJson("/api/hr/employees/{$employee->id}/skills", ['skills' => ['PHP', 'React']])
            ->assertOk()
            ->assertJsonPath('employee_skills', ['PHP', 'React']);

        // The panel's load on the next visit — the value has to survive a reload,
        // which is the whole point of the column that nothing used to write.
        $this->getJson("/api/hr/employees/{$employee->id}/skills")
            ->assertOk()
            ->assertJsonPath('employee_skills', ['PHP', 'React']);
    }

    public function test_the_skill_score_is_computed_against_the_positions_expected_skills(): void
    {
        $designation = HrDesignation::create([
            'tenant_id' => self::TENANT, 'name' => 'Platform Engineer',
            'skills' => ['PHP', 'React', 'Terraform', 'Go'],
        ]);

        $employee = $this->employee(['designation_id' => $designation->id, 'designation' => 'Platform Engineer']);

        $body = $this->putJson("/api/hr/employees/{$employee->id}/skills", ['skills' => ['PHP', 'React', 'Kubernetes']])
            ->assertOk()
            ->assertJsonPath('overall.matched', ['PHP', 'React'])
            ->assertJsonPath('overall.missing', ['Terraform', 'Go'])
            ->assertJsonPath('configured', true)
            ->json();

        // Compared numerically: round() yields 50.0, which JSON serialises as 50,
        // and assertJsonPath compares strictly.
        //
        // Two of the four expected skills are held. Before the editor existed this
        // was always 0% with everything missing, because nothing could put a skill
        // on an employee at all.
        $this->assertEqualsWithDelta(50, $body['overall']['score'], 0.01);
    }

    public function test_clearing_the_skill_list_is_allowed(): void
    {
        $employee = $this->employee(['skills' => ['PHP']]);

        // 'present|array' — an empty list must be a legitimate save, or a skill
        // added by mistake could never be removed.
        $this->putJson("/api/hr/employees/{$employee->id}/skills", ['skills' => []])
            ->assertOk()
            ->assertJsonPath('employee_skills', []);
    }

    public function test_employee_skills_are_tenant_scoped(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $foreign = HrEmployee::create([
            'tenant_id' => 2, 'name' => 'Other', 'employee_code' => 'X1',
            'department' => 'Ops', 'designation' => 'Ops', 'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);

        $this->getJson("/api/hr/employees/{$foreign->id}/skills")->assertStatus(404);
        $this->putJson("/api/hr/employees/{$foreign->id}/skills", ['skills' => ['X']])->assertStatus(404);
    }

    /* ══ #38 — employee attendance tab ═════════════════════════════════ */

    public function test_the_employee_attendance_month_returns_the_keys_the_panel_renders(): void
    {
        $employee = $this->employee();

        $this->getJson("/api/hr/employees/{$employee->id}/attendance?month=2026-08")
            ->assertOk()
            ->assertJsonPath('month', '2026-08')
            ->assertJsonPath('month_label', 'August 2026')
            ->assertJsonStructure([
                'month', 'month_label', 'present_count', 'late_count', 'absent_count',
                'leave_count', 'half_day_count', 'overtime_hours', 'working_hours',
                'attendance_pct', 'calendar',
            ]);
    }

    public function test_a_month_with_nothing_synced_is_an_empty_calendar_not_an_error(): void
    {
        $employee = $this->employee();

        // The panel distinguishes "no data yet" from "failed"; a 500 here would
        // have shown as a broken tab for every employee before their first sync.
        $this->getJson("/api/hr/employees/{$employee->id}/attendance?month=2001-01")
            ->assertOk()
            ->assertJsonPath('calendar', []);
    }

    public function test_the_manual_sync_reports_that_the_integration_is_off(): void
    {
        config(['sangoetrack.enabled' => false]);
        $employee = $this->employee();

        // The Sync button must get a usable answer rather than a generic failure —
        // "disabled" is a configuration fact the user can act on.
        $this->postJson('/api/hr/attendance/sync-sangoetrack', [
            'employee_id' => $employee->id, 'month' => 8, 'year' => 2026,
        ])->assertStatus(422)->assertJsonPath('status', 'error');
    }

    public function test_attendance_is_tenant_scoped(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $foreign = HrEmployee::create([
            'tenant_id' => 2, 'name' => 'Other', 'employee_code' => 'X2',
            'department' => 'Ops', 'designation' => 'Ops', 'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);

        $this->getJson("/api/hr/employees/{$foreign->id}/attendance")->assertStatus(404);
    }

    /* ══ #25 — quiz authoring over HTTP ════════════════════════════════ */

    private function questionPayload(array $overrides = []): array
    {
        return array_merge([
            'question_text' => 'Which are safety hazards on a scaffold?',
            'question_type' => HrQuizQuestion::MULTIPLE,
            'marks'         => 2,
            'options'       => [
                ['option_text' => 'Missing guardrail', 'is_correct' => true],
                ['option_text' => 'Wet planking',      'is_correct' => true],
                ['option_text' => 'Blue helmet',       'is_correct' => false],
            ],
        ], $overrides);
    }

    public function test_a_question_with_options_and_an_answer_key_can_be_created(): void
    {
        $this->postJson('/api/hr/learning/quiz/questions', $this->questionPayload())
            ->assertCreated()
            ->assertJsonPath('question_type', HrQuizQuestion::MULTIPLE)
            ->assertJsonCount(3, 'options');
    }

    public function test_the_authoring_list_includes_the_answer_key(): void
    {
        $this->postJson('/api/hr/learning/quiz/questions', $this->questionPayload())->assertCreated();

        $rows = $this->getJson('/api/hr/learning/quiz/questions')->assertOk()->json('data');

        // The bank IS the authoring view — without is_correct the builder cannot
        // show which option is the answer. (The paper an employee sits is a
        // different payload and still withholds it.)
        $correct = collect($rows[0]['options'])->where('is_correct', true)->pluck('option_text')->all();
        $this->assertSame(['Missing guardrail', 'Wet planking'], $correct);
    }

    public function test_a_question_can_be_edited(): void
    {
        $id = $this->postJson('/api/hr/learning/quiz/questions', $this->questionPayload())->json('id');

        $this->putJson("/api/hr/learning/quiz/questions/{$id}", $this->questionPayload([
            'options' => [
                ['option_text' => 'Missing guardrail', 'is_correct' => true],
                ['option_text' => 'Overloading',       'is_correct' => true],
            ],
        ]))->assertOk()->assertJsonCount(2, 'options');
    }

    public function test_a_question_needs_two_options_and_a_correct_answer(): void
    {
        $this->postJson('/api/hr/learning/quiz/questions', $this->questionPayload([
            'options' => [['option_text' => 'Only one', 'is_correct' => true]],
        ]))->assertStatus(422)->assertJsonValidationErrors('options');

        $this->postJson('/api/hr/learning/quiz/questions', $this->questionPayload([
            'options' => [
                ['option_text' => 'A', 'is_correct' => false],
                ['option_text' => 'B', 'is_correct' => false],
            ],
        ]))->assertStatus(422);
    }

    public function test_a_quiz_can_be_assembled_from_bank_questions_and_read_back(): void
    {
        $questionId = $this->postJson('/api/hr/learning/quiz/questions', $this->questionPayload())->json('id');

        $quizId = $this->postJson('/api/hr/learning/quiz', [
            'name' => 'Scaffold Safety', 'code' => 'SS-1',
            'pass_percentage' => 60, 'duration_minutes' => 15, 'max_attempts' => 2,
            'show_correct_answers' => true, 'is_active' => true,
            // marks_override: worth 3 on this quiz, 2 everywhere else.
            'questions' => [['question_id' => $questionId, 'marks_override' => 3]],
        ])->assertCreated()->json('id');

        $quiz = $this->getJson("/api/hr/learning/quiz/{$quizId}")
            ->assertOk()
            ->assertJsonPath('name', 'Scaffold Safety')
            ->assertJsonCount(1, 'questions')
            ->json();

        // Numeric comparison: the override makes this question worth 3 here even
        // though the bank says 2. (3.0 serialises as 3; assertJsonPath is strict.)
        $this->assertEqualsWithDelta(3, $quiz['total_marks'], 0.01);

        // The listing the table renders.
        $this->getJson('/api/hr/learning/quiz')
            ->assertOk()
            ->assertJsonPath('data.0.question_count', 1);
    }

    public function test_a_quiz_and_a_question_can_be_deleted(): void
    {
        $questionId = $this->postJson('/api/hr/learning/quiz/questions', $this->questionPayload())->json('id');
        $quizId = $this->postJson('/api/hr/learning/quiz', [
            'name' => 'Temp', 'questions' => [['question_id' => $questionId]],
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/hr/learning/quiz/{$quizId}")->assertOk();
        $this->deleteJson("/api/hr/learning/quiz/questions/{$questionId}")->assertOk();

        $this->assertSame([], $this->getJson('/api/hr/learning/quiz/questions')->json('data'));
    }

    public function test_quiz_authoring_requires_hr_permission(): void
    {
        $viewer = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Viewer', 'email' => 'v'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/hr/learning/quiz/questions', $this->questionPayload())->assertStatus(403);
    }

    /* ══ #25 — sitting a quiz: start → answer → submit → review ════════ */

    /** A quiz with one 2-mark multi-select and one 1-mark true/false. */
    private function sittableQuiz(array $quizOverrides = []): array
    {
        $multi = $this->postJson('/api/hr/learning/quiz/questions', $this->questionPayload())->json();
        $bool  = $this->postJson('/api/hr/learning/quiz/questions', [
            'question_text' => 'Helmets are mandatory on site',
            'question_type' => HrQuizQuestion::BOOLEAN,
            'marks'         => 1,
            'options'       => [
                ['option_text' => 'True',  'is_correct' => true],
                ['option_text' => 'False', 'is_correct' => false],
            ],
        ])->json();

        $quiz = $this->postJson('/api/hr/learning/quiz', array_merge([
            'name' => 'Scaffold Safety', 'pass_percentage' => 60,
            'max_attempts' => 2, 'show_correct_answers' => true, 'is_active' => true,
            'questions' => [['question_id' => $multi['id']], ['question_id' => $bool['id']]],
        ], $quizOverrides))->assertCreated()->json();

        return [$quiz, $multi, $bool];
    }

    /** option id by its text, from the authoring payload (which carries the key). */
    private function optionId(array $question, string $text): int
    {
        return collect($question['options'])->firstWhere('option_text', $text)['id'];
    }

    public function test_the_paper_an_employee_sits_never_carries_the_answer_key(): void
    {
        [$quiz] = $this->sittableQuiz();
        $employee = $this->employee();

        $paper = $this->postJson("/api/hr/learning/quiz/{$quiz['id']}/start", ['employee_id' => $employee->id])
            ->assertCreated()
            ->json();

        // The runner renders exactly this payload. If is_correct appeared here the
        // answers would be readable in the browser while the quiz is being sat.
        foreach ($paper['questions'] as $question) {
            foreach ($question['options'] as $option) {
                $this->assertArrayNotHasKey('is_correct', $option, 'The answer key leaked into the paper.');
            }
        }

        $this->assertSame(1, $paper['attempt_number']);
        $this->assertEqualsWithDelta(3, $paper['total_marks'], 0.01);
    }

    public function test_an_attempt_is_marked_on_submit_and_can_be_read_back(): void
    {
        [$quiz, $multi, $bool] = $this->sittableQuiz();
        $employee = $this->employee();

        $paper = $this->postJson("/api/hr/learning/quiz/{$quiz['id']}/start", ['employee_id' => $employee->id])->json();

        // Half the multi-select, plus the correct boolean.
        $result = $this->postJson("/api/hr/learning/quiz/attempts/{$paper['attempt_id']}/submit", [
            'answers' => [
                ['question_id' => $multi['id'], 'selected_option_ids' => [$this->optionId($multi, 'Missing guardrail')]],
                ['question_id' => $bool['id'],  'selected_option_ids' => [$this->optionId($bool, 'True')]],
            ],
        ])->assertOk()->json();

        // A partially correct multiple-answer scores nothing — the engine's rule,
        // asserted here so the runner cannot drift from it.
        $this->assertEqualsWithDelta(1, $result['obtained_marks'], 0.01);
        $this->assertEqualsWithDelta(33.33, $result['percentage'], 0.01);
        $this->assertFalse($result['passed']);

        // The review screen reads the same attempt back by id.
        $this->getJson("/api/hr/learning/quiz/attempts/{$paper['attempt_id']}")
            ->assertOk()
            ->assertJsonPath('passed', false)
            ->assertJsonPath('attempt_number', 1);
    }

    public function test_a_fully_correct_attempt_passes(): void
    {
        [$quiz, $multi, $bool] = $this->sittableQuiz();
        $employee = $this->employee();

        $paper = $this->postJson("/api/hr/learning/quiz/{$quiz['id']}/start", ['employee_id' => $employee->id])->json();

        $this->postJson("/api/hr/learning/quiz/attempts/{$paper['attempt_id']}/submit", [
            'answers' => [
                ['question_id' => $multi['id'], 'selected_option_ids' => [
                    $this->optionId($multi, 'Missing guardrail'), $this->optionId($multi, 'Wet planking'),
                ]],
                ['question_id' => $bool['id'], 'selected_option_ids' => [$this->optionId($bool, 'True')]],
            ],
        ])->assertOk()
          ->assertJsonPath('passed', true)
          // Numeric: 100.0 serialises as 100 and assertJsonPath compares strictly.
          ->assertJsonPath('percentage', fn ($v) => abs($v - 100) < 0.01);
    }

    public function test_starting_again_resumes_an_open_attempt_instead_of_creating_a_second(): void
    {
        [$quiz] = $this->sittableQuiz();
        $employee = $this->employee();

        $first  = $this->postJson("/api/hr/learning/quiz/{$quiz['id']}/start", ['employee_id' => $employee->id])->json();
        $second = $this->postJson("/api/hr/learning/quiz/{$quiz['id']}/start", ['employee_id' => $employee->id])->json();

        // Closing the runner without submitting must not burn an attempt.
        $this->assertSame($first['attempt_id'], $second['attempt_id']);
        $this->assertSame(1, $second['attempt_number']);
    }

    public function test_the_attempt_limit_is_enforced_once_nothing_is_open(): void
    {
        [$quiz, $multi, $bool] = $this->sittableQuiz(['max_attempts' => 1]);
        $employee = $this->employee();

        $paper = $this->postJson("/api/hr/learning/quiz/{$quiz['id']}/start", ['employee_id' => $employee->id])->json();
        $this->postJson("/api/hr/learning/quiz/attempts/{$paper['attempt_id']}/submit", [
            'answers' => [
                ['question_id' => $multi['id'], 'selected_option_ids' => []],
                ['question_id' => $bool['id'],  'selected_option_ids' => []],
            ],
        ])->assertOk();

        // The drawer hides the Start button on this, but the server is the guard.
        $this->postJson("/api/hr/learning/quiz/{$quiz['id']}/start", ['employee_id' => $employee->id])
            ->assertStatus(422);
    }

    public function test_the_history_the_assignment_drawer_lists(): void
    {
        [$quiz, $multi, $bool] = $this->sittableQuiz();
        $employee = $this->employee();

        $paper = $this->postJson("/api/hr/learning/quiz/{$quiz['id']}/start", ['employee_id' => $employee->id])->json();
        $this->postJson("/api/hr/learning/quiz/attempts/{$paper['attempt_id']}/submit", [
            'answers' => [
                ['question_id' => $multi['id'], 'selected_option_ids' => [
                    $this->optionId($multi, 'Missing guardrail'), $this->optionId($multi, 'Wet planking'),
                ]],
                ['question_id' => $bool['id'], 'selected_option_ids' => [$this->optionId($bool, 'True')]],
            ],
        ])->assertOk();

        $this->getJson("/api/hr/learning/quiz/employees/{$employee->id}/history")
            ->assertOk()
            ->assertJsonPath('total_attempts', 1)
            ->assertJsonPath('passed_count', 1)
            ->assertJsonPath('attempts.0.quiz_id', $quiz['id']);
    }

    public function test_a_quiz_with_no_questions_cannot_be_started(): void
    {
        $quiz = $this->postJson('/api/hr/learning/quiz', ['name' => 'Empty'])->assertCreated()->json();
        $employee = $this->employee();

        // The runner surfaces this message rather than showing a blank paper.
        $this->postJson("/api/hr/learning/quiz/{$quiz['id']}/start", ['employee_id' => $employee->id])
            ->assertStatus(422);
    }

    public function test_the_attempt_flow_is_tenant_scoped(): void
    {
        [$quiz] = $this->sittableQuiz();

        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $foreign = HrEmployee::create([
            'tenant_id' => 2, 'name' => 'Other', 'employee_code' => 'X3',
            'department' => 'Ops', 'designation' => 'Ops', 'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);

        // Another tenant's employee must not be sittable, and an attempt id that
        // is not ours must not be readable.
        $this->postJson("/api/hr/learning/quiz/{$quiz['id']}/start", ['employee_id' => $foreign->id])
            ->assertStatus(404);
        $this->getJson('/api/hr/learning/quiz/attempts/999999')->assertStatus(404);
    }

    public function test_sitting_a_quiz_does_not_require_hr_permission(): void
    {
        [$quiz] = $this->sittableQuiz();
        $employee = $this->employee();

        $viewer = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Learner', 'email' => 'l'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]);
        Sanctum::actingAs($viewer);

        // Authoring is gated; sitting is not — gating it would make the feature
        // unusable by the people it exists for.
        $this->postJson("/api/hr/learning/quiz/{$quiz['id']}/start", ['employee_id' => $employee->id])
            ->assertCreated();
    }
}
