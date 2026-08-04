<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrQuizQuestion;
use App\Services\Hr\QuizService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * #25 — question bank, quiz assembly, attempts and evaluation.
 *
 * Authoring (bank + quiz) requires HR-queue management. SITTING a quiz does not:
 * the employee taking it is the point, and gating it behind an HR permission
 * would make the feature unusable by the people it is for.
 */
class QuizController extends Controller
{
    public function __construct(private QuizService $service)
    {
    }

    public function meta()
    {
        return response()->json(['question_types' => HrQuizQuestion::TYPES]);
    }

    /* ── Question bank ────────────────────────────────────────────────── */

    public function questions(Request $request)
    {
        return response()->json([
            'data' => $this->service->questions($this->tenant($request),
                $request->only(['category_id', 'question_type', 'is_active', 'search'])),
        ]);
    }

    public function saveQuestion(Request $request, ?int $id = null)
    {
        $this->can($request);
        $data = $request->validate([
            'category_id'        => 'nullable|integer',
            'question_text'      => ($id ? 'sometimes|' : '').'required|string|max:2000',
            'question_type'      => ['nullable', Rule::in(HrQuizQuestion::TYPES)],
            'marks'              => 'nullable|numeric|min:0|max:1000',
            'explanation'        => 'nullable|string|max:2000',
            'is_active'          => 'nullable|boolean',
            'options'            => 'required|array|min:2',
            'options.*.option_text' => 'required|string|max:500',
            'options.*.is_correct'  => 'nullable|boolean',
        ]);

        return response()->json(
            $this->service->saveQuestion($id, $data, $this->tenant($request), $request->user()),
            $id ? 200 : 201
        );
    }

    public function destroyQuestion(Request $request, int $id)
    {
        $this->can($request);
        $this->service->deleteQuestion($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Quizzes ──────────────────────────────────────────────────────── */

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->quizzes($this->tenant($request), $request->only(['training_program_id', 'is_active'])),
        ]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->showQuiz($id, $this->tenant($request)));
    }

    public function save(Request $request, ?int $id = null)
    {
        $this->can($request);
        $data = $request->validate([
            'name'                 => ($id ? 'sometimes|' : '').'required|string|max:150',
            'code'                 => 'nullable|string|max:40',
            'training_program_id'  => 'nullable|integer',
            'description'          => 'nullable|string|max:2000',
            'pass_percentage'      => 'nullable|numeric|min:0|max:100',
            'duration_minutes'     => 'nullable|integer|min:1|max:1440',
            'max_attempts'         => 'nullable|integer|min:1|max:100',
            'shuffle_questions'    => 'nullable|boolean',
            'show_correct_answers' => 'nullable|boolean',
            'is_active'            => 'nullable|boolean',
            'questions'            => 'nullable|array',
            'questions.*.question_id'    => 'required|integer',
            'questions.*.marks_override' => 'nullable|numeric|min:0|max:1000',
        ]);

        return response()->json(
            $this->service->saveQuiz($id, $data, $this->tenant($request), $request->user()),
            $id ? 200 : 201
        );
    }

    public function destroy(Request $request, int $id)
    {
        $this->can($request);
        $this->service->deleteQuiz($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Attempts ─────────────────────────────────────────────────────── */

    public function start(Request $request, int $id)
    {
        $data = $request->validate([
            'employee_id'          => 'required|integer',
            'employee_training_id' => 'nullable|integer',
        ]);

        return response()->json($this->service->startAttempt(
            $id, (int) $data['employee_id'], $this->tenant($request),
            $data['employee_training_id'] ?? null, $request->user()
        ), 201);
    }

    public function submit(Request $request, int $attemptId)
    {
        $data = $request->validate([
            'answers'                          => 'present|array',
            'answers.*.question_id'            => 'required|integer',
            'answers.*.selected_option_ids'    => 'present|array',
            'answers.*.selected_option_ids.*'  => 'integer',
        ]);

        return response()->json($this->service->submitAttempt(
            $attemptId, $data['answers'], $this->tenant($request), $request->user()
        ));
    }

    public function result(Request $request, int $attemptId)
    {
        return response()->json($this->service->result($attemptId, $this->tenant($request)));
    }

    public function employeeHistory(Request $request, int $employeeId)
    {
        $quizId = $request->query('quiz_id');

        return response()->json($this->service->employeeHistory(
            $employeeId, $this->tenant($request), $quizId ? (int) $quizId : null
        ));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage quizzes');
    }
}
