<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeTraining;
use App\Models\Hr\HrQuiz;
use App\Models\Hr\HrQuizAnswer;
use App\Models\Hr\HrQuizAttempt;
use App\Models\Hr\HrQuizItem;
use App\Models\Hr\HrQuizQuestion;
use App\Models\Hr\HrQuizQuestionOption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Review comment #25 — "Quiz: how to set multiple questions and their answer?".
 *
 * Covers the question bank, quiz assembly, attempts and evaluation. The legacy
 * `hr_training_quizzes` score record is untouched and keeps working.
 *
 * EVALUATION IS ALL-OR-NOTHING PER QUESTION. A multiple-choice question scores
 * its marks only when the selected set matches the correct set exactly — no
 * partial credit for picking two of three right answers, and no credit at all for
 * selecting everything. Partial-credit schemes are a business decision nobody has
 * specified, and "select all the options" scoring full marks is the failure mode
 * that makes a quiz worthless.
 *
 * THE ANSWER KEY NEVER LEAVES THE SERVER while an attempt is open. `forAttempt()`
 * strips `is_correct` from the options; it is only included once the attempt has
 * been submitted, and only when the quiz says to show it.
 */
class QuizService
{
    /* ══ Question bank ═══════════════════════════════════════════════ */

    public function questions(int $tenantId, array $filters = []): array
    {
        $q = HrQuizQuestion::forTenant($tenantId)->with(['options', 'category:id,name']);

        if (! empty($filters['category_id'])) {
            $q->where('category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['question_type'])) {
            $q->where('question_type', $filters['question_type']);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['search'])) {
            $q->where('question_text', 'like', '%'.$filters['search'].'%');
        }

        return $q->orderByDesc('id')->get()->map(fn ($x) => $this->presentQuestion($x, withKey: true))->all();
    }

    public function saveQuestion(?int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $type = $data['question_type'] ?? HrQuizQuestion::SINGLE;
        $options = $data['options'] ?? [];

        $this->assertAnswerable($type, $options);

        $question = DB::transaction(function () use ($id, $data, $type, $options, $tenantId, $actor) {
            $attrs = [
                'category_id'   => $data['category_id'] ?? null,
                'question_text' => $data['question_text'] ?? null,
                'question_type' => $type,
                'marks'         => round((float) ($data['marks'] ?? 1), 2),
                'explanation'   => $data['explanation'] ?? null,
                'is_active'     => $data['is_active'] ?? true,
            ];

            if ($id) {
                $question = $this->findQuestion($id, $tenantId);
                $question->update($attrs + ['updated_by' => $actor?->id]);
            } else {
                $question = HrQuizQuestion::create($attrs + ['tenant_id' => $tenantId, 'created_by' => $actor?->id]);
            }

            // Replaced wholesale: a merge would leave a deleted option behind, and
            // a stale option is one someone can still be marked wrong for picking.
            $question->options()->delete();
            foreach (array_values($options) as $i => $opt) {
                HrQuizQuestionOption::create([
                    'tenant_id'   => $tenantId,
                    'question_id' => $question->id,
                    'option_text' => $opt['option_text'] ?? $opt['text'] ?? '',
                    'is_correct'  => (bool) ($opt['is_correct'] ?? false),
                    'sort_order'  => $i,
                ]);
            }

            return $question;
        });

        $question->recordAudit($id ? 'Quiz Question Updated' : 'Quiz Question Created', $actor);

        return $this->presentQuestion($question->fresh(['options', 'category']), withKey: true);
    }

    public function deleteQuestion(int $id, int $tenantId, ?User $actor = null): void
    {
        $question = $this->findQuestion($id, $tenantId);

        // Deleting a question that has been answered would orphan the answers and
        // silently change historical scores. Deactivate instead.
        if (HrQuizAnswer::forTenant($tenantId)->where('question_id', $id)->exists()) {
            throw new BusinessException('This question has been answered in a quiz attempt. Deactivate it instead of deleting it.');
        }
        if (HrQuizItem::forTenant($tenantId)->where('question_id', $id)->exists()) {
            throw new BusinessException('This question is used by a quiz. Remove it from the quiz first.');
        }

        $question->recordAudit('Quiz Question Deleted', $actor);
        $question->options()->delete();
        $question->delete();
    }

    /* ══ Quiz assembly ═══════════════════════════════════════════════ */

    public function quizzes(int $tenantId, array $filters = []): array
    {
        $q = HrQuiz::forTenant($tenantId)->with(['items.question', 'program:id,program_name'])->withCount('attempts');

        if (! empty($filters['training_program_id'])) {
            $q->where('training_program_id', (int) $filters['training_program_id']);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $q->orderBy('name')->get()->map(fn ($x) => $this->presentQuiz($x))->all();
    }

    public function showQuiz(int $id, int $tenantId): array
    {
        return $this->presentQuiz($this->findQuiz($id, $tenantId), withQuestions: true);
    }

    public function saveQuiz(?int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $quiz = DB::transaction(function () use ($id, $data, $tenantId, $actor) {
            $attrs = array_filter([
                'name'                 => $data['name'] ?? null,
                'code'                 => $data['code'] ?? null,
                'training_program_id'  => $data['training_program_id'] ?? null,
                'description'          => $data['description'] ?? null,
                'pass_percentage'      => $data['pass_percentage'] ?? null,
                'duration_minutes'     => $data['duration_minutes'] ?? null,
                'max_attempts'         => $data['max_attempts'] ?? null,
            ], fn ($v) => $v !== null);

            foreach (['shuffle_questions', 'show_correct_answers', 'is_active'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $attrs[$flag] = (bool) $data[$flag];
                }
            }

            if ($id) {
                $quiz = $this->findQuiz($id, $tenantId);
                $this->assertNotAttempted($quiz, 'Its questions cannot be changed once employees have attempted it.');
                $quiz->update($attrs + ['updated_by' => $actor?->id]);
            } else {
                $quiz = HrQuiz::create($attrs + ['tenant_id' => $tenantId, 'created_by' => $actor?->id]);
            }

            if (array_key_exists('questions', $data)) {
                $quiz->items()->delete();
                foreach (array_values($data['questions'] ?? []) as $i => $item) {
                    $questionId = (int) ($item['question_id'] ?? $item);
                    $this->findQuestion($questionId, $tenantId);   // tenant guard
                    HrQuizItem::create([
                        'tenant_id'      => $tenantId,
                        'quiz_id'        => $quiz->id,
                        'question_id'    => $questionId,
                        'marks_override' => is_array($item) ? ($item['marks_override'] ?? null) : null,
                        'sort_order'     => $i,
                    ]);
                }
            }

            return $quiz;
        });

        $quiz->recordAudit($id ? 'Quiz Updated' : 'Quiz Created', $actor, null, ['name' => $quiz->name]);

        return $this->presentQuiz($quiz->fresh(['items.question', 'program']), withQuestions: true);
    }

    public function deleteQuiz(int $id, int $tenantId, ?User $actor = null): void
    {
        $quiz = $this->findQuiz($id, $tenantId);
        $this->assertNotAttempted($quiz, 'Deactivate it instead of deleting it.');

        $quiz->recordAudit('Quiz Deleted', $actor, null, ['name' => $quiz->name]);
        $quiz->items()->delete();
        $quiz->delete();
    }

    /* ══ Attempts ════════════════════════════════════════════════════ */

    /**
     * Start an attempt. Returns the paper WITHOUT the answer key.
     *
     * Assignment is implicit: an employee starts the quiz attached to a training
     * they hold. There is no separate assignment table because the training
     * assignment already IS the assignment — a second one would drift from it.
     */
    public function startAttempt(int $quizId, int $employeeId, int $tenantId, ?int $employeeTrainingId = null, ?User $actor = null): array
    {
        $quiz = $this->findQuiz($quizId, $tenantId);
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);

        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }
        if (! $quiz->is_active) {
            throw new BusinessException('This quiz is not active.');
        }
        if ($quiz->items->isEmpty()) {
            throw new BusinessException('This quiz has no questions yet.');
        }

        if ($employeeTrainingId) {
            $training = HrEmployeeTraining::where('tenant_id', $tenantId)->find($employeeTrainingId);
            if (! $training || (int) $training->employee_id !== $employeeId) {
                throw new BusinessException('That training assignment does not belong to this employee.');
            }
        }

        $prior = HrQuizAttempt::forTenant($tenantId)->where('quiz_id', $quizId)->where('employee_id', $employeeId);

        if ($open = (clone $prior)->where('status', HrQuizAttempt::IN_PROGRESS)->first()) {
            // Resume rather than start a second — two open attempts would make
            // "which one counts?" unanswerable.
            return $this->forAttempt($open, $quiz);
        }

        $used = (clone $prior)->count();
        if ($quiz->max_attempts !== null && $used >= (int) $quiz->max_attempts) {
            throw new BusinessException("This quiz allows at most {$quiz->max_attempts} attempt(s).");
        }

        $attempt = HrQuizAttempt::create([
            'tenant_id'            => $tenantId,
            'quiz_id'              => $quiz->id,
            'employee_id'          => $employeeId,
            'employee_training_id' => $employeeTrainingId,
            'attempt_number'       => $used + 1,
            'status'               => HrQuizAttempt::IN_PROGRESS,
            'started_at'           => now(),
            'pass_percentage'      => $quiz->pass_percentage,
        ]);
        $attempt->recordAudit('Quiz Started', $actor, null, ['quiz' => $quiz->name, 'attempt' => $used + 1]);

        return $this->forAttempt($attempt, $quiz);
    }

    /**
     * Submit and evaluate in one step.
     *
     * Evaluation is immediate because every question type here is objectively
     * markable — there is no free-text question in a quiz, so nothing waits for a
     * human. Marks are frozen onto the attempt at this moment.
     */
    public function submitAttempt(int $attemptId, array $answers, int $tenantId, ?User $actor = null): array
    {
        $attempt = $this->findAttempt($attemptId, $tenantId);

        if ($attempt->status !== HrQuizAttempt::IN_PROGRESS) {
            throw new BusinessException('This attempt has already been submitted.');
        }

        $quiz = $this->findQuiz((int) $attempt->quiz_id, $tenantId);
        $byQuestion = collect($answers)->keyBy(fn ($a) => (int) ($a['question_id'] ?? 0));

        $total = $obtained = 0.0;

        DB::transaction(function () use ($attempt, $quiz, $byQuestion, $tenantId, &$total, &$obtained) {
            $attempt->answers()->delete();

            foreach ($quiz->items as $item) {
                $question = $item->question;
                if (! $question) {
                    continue;
                }

                $marks = $item->marks();
                $total += $marks;

                $selected = array_map('intval', (array) ($byQuestion[$question->id]['selected_option_ids'] ?? []));
                sort($selected);
                $correct = $question->correctOptionIds();
                sort($correct);

                // Exact set match — see the class note on why there is no partial credit.
                $isCorrect = $selected !== [] && $selected === $correct;
                $awarded = $isCorrect ? $marks : 0.0;
                $obtained += $awarded;

                HrQuizAnswer::create([
                    'tenant_id'           => $tenantId,
                    'attempt_id'          => $attempt->id,
                    'question_id'         => $question->id,
                    'selected_option_ids' => $selected,
                    'is_correct'          => $isCorrect,
                    'marks_awarded'       => $awarded,
                ]);
            }

            $percentage = $total > 0 ? round($obtained / $total * 100, 2) : 0.0;

            $attempt->update([
                'status'         => HrQuizAttempt::EVALUATED,
                'submitted_at'   => now(),
                'total_marks'    => round($total, 2),
                'obtained_marks' => round($obtained, 2),
                'percentage'     => $percentage,
                // Frozen from the quiz as it stands now — the quiz may be retuned later.
                'pass_percentage' => $quiz->pass_percentage,
                'passed'         => $percentage >= (float) $quiz->pass_percentage,
            ]);
        });

        $attempt->refresh();
        $attempt->recordAudit('Quiz Submitted', $actor, null, [
            'quiz' => $quiz->name, 'percentage' => $attempt->percentage, 'passed' => $attempt->passed,
        ]);
        Log::channel('hr')->info('Quiz attempt evaluated', [
            'tenant_id' => $tenantId, 'attempt_id' => $attempt->id, 'passed' => $attempt->passed,
        ]);

        return $this->presentResult($attempt, $quiz);
    }

    /** #25 — an employee's quiz history: every attempt, newest first. */
    public function employeeHistory(int $employeeId, int $tenantId, ?int $quizId = null): array
    {
        $attempts = HrQuizAttempt::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->when($quizId, fn ($q) => $q->where('quiz_id', $quizId))
            ->with('quiz:id,name,pass_percentage')
            ->orderByDesc('id')->get();

        return [
            'employee_id'    => $employeeId,
            'total_attempts' => $attempts->count(),
            'passed_count'   => $attempts->where('passed', true)->count(),
            'best_percentage' => $attempts->max('percentage') !== null ? (float) $attempts->max('percentage') : null,
            'attempts' => $attempts->map(fn ($a) => [
                'id'             => $a->id,
                'quiz_id'        => $a->quiz_id,
                'quiz_name'      => $a->quiz?->name,
                'attempt_number' => (int) $a->attempt_number,
                'status'         => $a->status,
                'total_marks'    => (float) $a->total_marks,
                'obtained_marks' => (float) $a->obtained_marks,
                'percentage'     => (float) $a->percentage,
                'passed'         => (bool) $a->passed,
                'submitted_at'   => optional($a->submitted_at)->toIso8601String(),
            ])->all(),
        ];
    }

    /** #25 — evaluation view for one attempt, with the key when allowed. */
    public function result(int $attemptId, int $tenantId): array
    {
        $attempt = $this->findAttempt($attemptId, $tenantId);

        return $this->presentResult($attempt, $this->findQuiz((int) $attempt->quiz_id, $tenantId));
    }

    /* ══ Guards + presenters ═════════════════════════════════════════ */

    /**
     * A question nobody can get right is a defect, not a hard question. Every
     * question needs at least two options and at least one correct answer;
     * single-choice and boolean need exactly one.
     */
    private function assertAnswerable(string $type, array $options): void
    {
        if (! in_array($type, HrQuizQuestion::TYPES, true)) {
            throw new BusinessException('Unknown question type.');
        }
        if (count($options) < 2) {
            throw new BusinessException('A question needs at least two options.');
        }

        $correct = count(array_filter($options, fn ($o) => ! empty($o['is_correct'])));
        if ($correct === 0) {
            throw new BusinessException('Mark at least one option as the correct answer.');
        }
        if ($type !== HrQuizQuestion::MULTIPLE && $correct > 1) {
            throw new BusinessException('A single-choice question can have only one correct answer.');
        }
    }

    private function assertNotAttempted(HrQuiz $quiz, string $suffix): void
    {
        if ($quiz->attempts()->exists()) {
            throw new BusinessException("This quiz has already been attempted. {$suffix}");
        }
    }

    private function findQuestion(int $id, int $tenantId): HrQuizQuestion
    {
        $q = HrQuizQuestion::forTenant($tenantId)->with('options')->find($id);
        if (! $q) {
            throw new BusinessException('Question not found', 404);
        }

        return $q;
    }

    private function findQuiz(int $id, int $tenantId): HrQuiz
    {
        $quiz = HrQuiz::forTenant($tenantId)->with(['items.question.options', 'program'])->find($id);
        if (! $quiz) {
            throw new BusinessException('Quiz not found', 404);
        }

        return $quiz;
    }

    private function findAttempt(int $id, int $tenantId): HrQuizAttempt
    {
        $attempt = HrQuizAttempt::forTenant($tenantId)->with(['answers', 'employee:id,name,employee_code'])->find($id);
        if (! $attempt) {
            throw new BusinessException('Quiz attempt not found', 404);
        }

        return $attempt;
    }

    private function presentQuestion(HrQuizQuestion $q, bool $withKey = false): array
    {
        return [
            'id'            => $q->id,
            'category_id'   => $q->category_id,
            'category_name' => $q->category?->name,
            'question_text' => $q->question_text,
            'question_type' => $q->question_type,
            'marks'         => (float) $q->marks,
            'explanation'   => $q->explanation,
            'is_active'     => (bool) $q->is_active,
            'options'       => $q->options->map(fn ($o) => array_filter([
                'id'          => $o->id,
                'option_text' => $o->option_text,
                'sort_order'  => $o->sort_order,
                // The answer key is included ONLY for authoring/review views.
                'is_correct'  => $withKey ? (bool) $o->is_correct : null,
            ], fn ($v) => $v !== null))->all(),
        ];
    }

    private function presentQuiz(HrQuiz $q, bool $withQuestions = false): array
    {
        $out = [
            'id'                   => $q->id,
            'name'                 => $q->name,
            'code'                 => $q->code,
            'training_program_id'  => $q->training_program_id,
            'program_name'         => $q->program?->program_name,
            'description'          => $q->description,
            'pass_percentage'      => (float) $q->pass_percentage,
            'duration_minutes'     => $q->duration_minutes,
            'max_attempts'         => $q->max_attempts,
            'shuffle_questions'    => (bool) $q->shuffle_questions,
            'show_correct_answers' => (bool) $q->show_correct_answers,
            'is_active'            => (bool) $q->is_active,
            'question_count'       => $q->relationLoaded('items') ? $q->items->count() : null,
            'total_marks'          => $q->relationLoaded('items') ? $q->totalMarks() : null,
            'attempts_count'       => $q->attempts_count ?? null,
        ];

        if ($withQuestions) {
            $out['questions'] = $q->items->map(fn ($i) => $this->presentQuestion($i->question, withKey: true) + [
                'marks_override' => $i->marks_override !== null ? (float) $i->marks_override : null,
                'effective_marks' => $i->marks(),
            ])->all();
        }

        return $out;
    }

    /** The paper an employee sits — deliberately WITHOUT the answer key. */
    private function forAttempt(HrQuizAttempt $attempt, HrQuiz $quiz): array
    {
        $items = $quiz->items;
        if ($quiz->shuffle_questions) {
            $items = $items->shuffle();
        }

        return [
            'attempt_id'      => $attempt->id,
            'quiz_id'         => $quiz->id,
            'quiz_name'       => $quiz->name,
            'attempt_number'  => (int) $attempt->attempt_number,
            'status'          => $attempt->status,
            'started_at'      => optional($attempt->started_at)->toIso8601String(),
            'duration_minutes' => $quiz->duration_minutes,
            'pass_percentage' => (float) $quiz->pass_percentage,
            'total_marks'     => $quiz->totalMarks(),
            'questions'       => $items->map(fn ($i) => $this->presentQuestion($i->question, withKey: false) + [
                'effective_marks' => $i->marks(),
            ])->values()->all(),
        ];
    }

    private function presentResult(HrQuizAttempt $attempt, HrQuiz $quiz): array
    {
        $answers = $attempt->answers->keyBy('question_id');
        $reveal = (bool) $quiz->show_correct_answers && $attempt->status !== HrQuizAttempt::IN_PROGRESS;

        return [
            'attempt_id'     => $attempt->id,
            'quiz_id'        => $quiz->id,
            'quiz_name'      => $quiz->name,
            'employee_id'    => $attempt->employee_id,
            'employee_name'  => $attempt->employee?->name,
            'attempt_number' => (int) $attempt->attempt_number,
            'status'         => $attempt->status,
            'total_marks'    => (float) $attempt->total_marks,
            'obtained_marks' => (float) $attempt->obtained_marks,
            'percentage'     => (float) $attempt->percentage,
            'pass_percentage' => (float) $attempt->pass_percentage,
            'passed'         => (bool) $attempt->passed,
            'submitted_at'   => optional($attempt->submitted_at)->toIso8601String(),
            'questions'      => $quiz->items->map(function ($i) use ($answers, $reveal) {
                $q = $i->question;
                $a = $answers[$q->id] ?? null;

                return $this->presentQuestion($q, withKey: $reveal) + [
                    'effective_marks'     => $i->marks(),
                    'selected_option_ids' => $a?->selected_option_ids ?? [],
                    'is_correct'          => (bool) $a?->is_correct,
                    'marks_awarded'       => (float) ($a?->marks_awarded ?? 0),
                    'explanation'         => $reveal ? $q->explanation : null,
                ];
            })->all(),
        ];
    }
}
