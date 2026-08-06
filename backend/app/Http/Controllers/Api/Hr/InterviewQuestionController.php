<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrInterviewQuestion;
use App\Models\Hr\HrInterviewRound;
use App\Services\Hr\InterviewQuestionAIService;
use App\Services\Hr\InterviewQuestionBankService;
use App\Services\Hr\InterviewQuestionSetService;
use Illuminate\Http\Request;

/**
 * #10 — the interview question bank, its sets, AI generation, and the round
 * integration.
 *
 * Authoring the bank is HR-gated. Reading questions for a round is not: the
 * interviewer running it is frequently not an HR user, and gating it would leave
 * them unable to see what they are supposed to ask.
 */
class InterviewQuestionController extends Controller
{
    public function __construct(
        private InterviewQuestionBankService $bank,
        private InterviewQuestionSetService $sets,
        private InterviewQuestionAIService $ai,
    ) {
    }

    /* ── Bank ─────────────────────────────────────────────────────────── */

    public function index(Request $request)
    {
        return response()->json(['data' => $this->bank->list($this->tenant($request), $request->only([
            'question_type', 'difficulty', 'category', 'designation_id',
            'source', 'is_active', 'search', 'skills', 'tag', 'experience',
        ]))]);
    }

    /** Facet values the filter bar needs, so the UI hardcodes no vocabulary. */
    public function meta(Request $request)
    {
        return response()->json([
            'types'        => HrInterviewQuestion::TYPES,
            'difficulties' => HrInterviewQuestion::DIFFICULTIES,
            'categories'   => $this->bank->categories($this->tenant($request)),
        ]);
    }

    public function store(Request $request)
    {
        $this->can($request);

        return response()->json($this->bank->save($request->all(), $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);

        return response()->json(
            $this->bank->save($request->all() + ['id' => $id], $this->tenant($request), $request->user())
        );
    }

    public function toggle(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->bank->toggle($id, $this->tenant($request), $request->user()));
    }

    public function destroy(Request $request, int $id)
    {
        $this->can($request);
        $this->bank->destroy($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Question removed']);
    }

    /* ── AI generation ────────────────────────────────────────────────── */

    /**
     * Generate drafts. Nothing is written to the bank here — the reviewer edits
     * and then saves, which is what makes Regenerate free of side effects.
     */
    public function generate(Request $request)
    {
        $this->can($request);

        $data = $request->validate([
            'job_posting_id'  => 'nullable|integer',
            'job_description' => 'nullable|string|max:8000',
            'designation'     => 'nullable|string|max:160',
            'skills'          => 'nullable|array',
            'skills.*'        => 'string|max:60',
            'experience_min'  => 'nullable|numeric|min:0|max:60',
            'experience_max'  => 'nullable|numeric|min:0|max:60',
            'count'           => 'nullable|integer|min:1|max:25',
            'types'           => 'nullable|array',
            'types.*'         => 'string|in:'.implode(',', HrInterviewQuestion::TYPES),
            'difficulty'      => 'nullable|in:'.implode(',', HrInterviewQuestion::DIFFICULTIES),
            'category'        => 'nullable|string|max:80',
        ]);

        return response()->json($this->ai->generate($data, $this->tenant($request), $request->user()));
    }

    /** Save reviewed drafts into the bank, keeping their AI provenance. */
    public function storeGenerated(Request $request)
    {
        $this->can($request);

        $data = $request->validate([
            'questions'                 => 'required|array|min:1',
            'questions.*.question_text' => 'required|string',
        ]);

        return response()->json([
            'data' => $this->bank->saveMany($data['questions'], $this->tenant($request), $request->user()),
        ], 201);
    }

    /* ── Sets ─────────────────────────────────────────────────────────── */

    public function sets(Request $request)
    {
        return response()->json(['data' => $this->sets->list(
            $this->tenant($request), $request->only(['designation_id', 'is_active'])
        )]);
    }

    public function storeSet(Request $request)
    {
        $this->can($request);

        return response()->json($this->sets->saveSet($request->all(), $this->tenant($request), $request->user()), 201);
    }

    public function updateSet(Request $request, int $id)
    {
        $this->can($request);

        return response()->json(
            $this->sets->saveSet($request->all() + ['id' => $id], $this->tenant($request), $request->user())
        );
    }

    public function destroySet(Request $request, int $id)
    {
        $this->can($request);
        $this->sets->destroySet($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Question set removed']);
    }

    /* ── Round integration ────────────────────────────────────────────── */

    public function roundQuestions(Request $request, HrInterviewRound $interviewRound)
    {
        return response()->json($this->sets->forRound($interviewRound, $this->tenant($request)));
    }

    public function attach(Request $request, HrInterviewRound $interviewRound)
    {
        $this->can($request);

        return response()->json($this->sets->attach(
            $interviewRound, $request->all(), $this->tenant($request), $request->user()
        ));
    }

    /** Scoring is the interviewer's job, so this is NOT gated on the HR queue. */
    public function evaluate(Request $request, HrInterviewRound $interviewRound)
    {
        $data = $request->validate([
            'answers'                       => 'required|array',
            'answers.*.id'                  => 'required|integer',
            'answers.*.score'               => 'nullable|numeric|min:0',
            'answers.*.answer_notes'        => 'nullable|string|max:4000',
            'answers.*.selected_options'    => 'nullable|array',
            'answers.*.selected_options.*'  => 'string|max:500',
        ]);

        return response()->json($this->sets->evaluate(
            $interviewRound, $data['answers'], $this->tenant($request), $request->user()
        ));
    }

    public function detach(Request $request, HrInterviewRound $interviewRound, int $roundQuestionId)
    {
        $this->can($request);

        return response()->json($this->sets->detach(
            $interviewRound, $roundQuestionId, $this->tenant($request), $request->user()
        ));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage interview questions');
    }
}
