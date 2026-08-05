<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrExitInterview;
use App\Models\Hr\HrExitInterviewAnswer;
use App\Models\Hr\HrExitQuestionnaire;
use App\Models\Hr\HrExitQuestionnaireQuestion;
use App\Models\Hr\HrSurveyQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Review comment #44 — exit questionnaire templates.
 *
 * Owns the template master and the answers given against it. It does NOT own the
 * exit interview itself: ExitInterviewService still creates and submits that
 * record, and this only adds the templated answers to it. Splitting it the other
 * way would have meant rewriting a working module to add an optional feature.
 */
class ExitQuestionnaireService
{
    /* ── Template master ──────────────────────────────────────────────── */

    public function list(int $tenantId, array $filters = []): array
    {
        $query = HrExitQuestionnaire::forTenant($tenantId)
            ->with(['questions', 'exitType:id,name']);

        if (isset($filters['is_active']) && $filters['is_active'] !== 'All') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['exit_type_id']) && $filters['exit_type_id'] !== 'All') {
            $query->where('exit_type_id', (int) $filters['exit_type_id']);
        }

        return $query->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn ($q) => $this->present($q))->all();
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId));
    }

    public function save(array $data, int $tenantId, ?User $actor = null): array
    {
        if (blank($data['name'] ?? null)) {
            throw new BusinessException('Give the questionnaire a name', 422);
        }

        return DB::transaction(function () use ($data, $tenantId, $actor) {
            $questionnaire = ! empty($data['id'])
                ? $this->find((int) $data['id'], $tenantId)
                : new HrExitQuestionnaire(['tenant_id' => $tenantId, 'created_by' => $actor?->id]);

            $questionnaire->fill([
                'tenant_id'    => $tenantId,
                'name'         => $data['name'],
                'code'         => $data['code'] ?? null,
                'description'  => $data['description'] ?? null,
                'exit_type_id' => $data['exit_type_id'] ?? null,
                'is_default'   => (bool) ($data['is_default'] ?? false),
                'is_active'    => (bool) ($data['is_active'] ?? true),
                'updated_by'   => $actor?->id,
            ])->save();

            // Exactly one default, or "the default" means nothing.
            if ($questionnaire->is_default) {
                HrExitQuestionnaire::forTenant($tenantId)
                    ->where('id', '!=', $questionnaire->id)
                    ->update(['is_default' => false]);
            }

            if (array_key_exists('questions', $data)) {
                $this->syncQuestions($questionnaire, (array) $data['questions'], $tenantId);
            }

            $questionnaire->recordAudit(
                empty($data['id']) ? 'Exit questionnaire created' : 'Exit questionnaire updated', $actor
            );

            return $this->present($questionnaire->fresh(['questions', 'exitType']));
        });
    }

    public function destroy(int $id, int $tenantId, ?User $actor = null): void
    {
        $questionnaire = $this->find($id, $tenantId);

        // Answers already given reference these questions. Deleting the template
        // would cascade them away and silently rewrite completed interviews, so a
        // used template is deactivated instead.
        $used = HrExitInterview::where('tenant_id', $tenantId)
            ->where('questionnaire_id', $id)->exists();

        if ($used) {
            $questionnaire->update(['is_active' => false]);
            $questionnaire->recordAudit('Exit questionnaire deactivated (already used)', $actor);

            return;
        }

        $questionnaire->recordAudit('Exit questionnaire deleted', $actor);
        $questionnaire->delete();
    }

    /**
     * The questionnaire to use for a given exit type.
     *
     * Most specific first: a template bound to this exit type, else the tenant
     * default, else nothing — and "nothing" is a valid answer that leaves the
     * original fixed form in play.
     */
    public function resolveFor(?int $exitTypeId, int $tenantId): ?array
    {
        $base = fn () => HrExitQuestionnaire::forTenant($tenantId)->where('is_active', true)->with('questions');

        $match = $exitTypeId
            ? $base()->where('exit_type_id', $exitTypeId)->first()
            : null;

        $match ??= $base()->where('is_default', true)->first();

        return $match ? $this->present($match) : null;
    }

    /* ── Answers ──────────────────────────────────────────────────────── */

    /**
     * Record the templated answers against an exit interview.
     *
     * Answers are replaced wholesale for the questions supplied, so re-saving a
     * draft interview does not accumulate duplicates.
     */
    public function saveAnswers(HrExitInterview $interview, int $questionnaireId, array $answers, int $tenantId): void
    {
        $questionnaire = $this->find($questionnaireId, $tenantId);
        $questions = $questionnaire->questions->keyBy('id');

        DB::transaction(function () use ($interview, $questionnaire, $questions, $answers, $tenantId) {
            $interview->update(['questionnaire_id' => $questionnaire->id]);

            foreach ($answers as $answer) {
                $question = $questions->get($answer['question_id'] ?? 0);

                // An answer to a question from another template is a bug in the
                // caller, not something to store quietly against this interview.
                if (! $question) {
                    continue;
                }

                HrExitInterviewAnswer::updateOrCreate(
                    ['exit_interview_id' => $interview->id, 'question_id' => $question->id],
                    ['tenant_id' => $tenantId] + $this->shapeAnswer($question, $answer)
                );
            }
        });
    }

    /**
     * Which required questions are still unanswered.
     *
     * Returned rather than thrown so a DRAFT interview can be saved incomplete —
     * the caller decides whether an incomplete answer set blocks submission.
     */
    public function missingRequired(HrExitInterview $interview): array
    {
        if (! $interview->questionnaire_id) {
            return [];
        }

        $answered = HrExitInterviewAnswer::where('exit_interview_id', $interview->id)
            ->get()->filter(fn ($a) => $a->answer_text !== null || $a->answer_rating !== null
                || $a->answer_boolean !== null || ! empty($a->answer_options))
            ->pluck('question_id')->all();

        return HrExitQuestionnaireQuestion::where('questionnaire_id', $interview->questionnaire_id)
            ->where('is_required', true)
            ->whereNotIn('id', $answered ?: [0])
            ->pluck('question_text')->all();
    }

    public function answersFor(HrExitInterview $interview): array
    {
        if (! $interview->questionnaire_id) {
            return [];
        }

        return HrExitInterviewAnswer::where('exit_interview_id', $interview->id)
            ->with('question')
            ->get()->map(fn ($a) => [
                'question_id'    => $a->question_id,
                'question_text'  => $a->question?->question_text,
                'question_type'  => $a->question?->question_type,
                'answer_text'    => $a->answer_text,
                'answer_rating'  => $a->answer_rating,
                'answer_boolean' => $a->answer_boolean,
                'answer_options' => $a->answer_options,
            ])->all();
    }

    /* ── Internals ────────────────────────────────────────────────────── */

    /**
     * The answer routed into the column its type belongs in.
     *
     * Every column is written on every save — the others explicitly to null — so
     * changing a question's type cannot leave a stale value behind in the column
     * the old type used.
     */
    private function shapeAnswer(HrExitQuestionnaireQuestion $question, array $answer): array
    {
        $blank = ['answer_text' => null, 'answer_rating' => null,
                  'answer_boolean' => null, 'answer_options' => null];

        $filled = match ($question->question_type) {
            HrSurveyQuestion::RATING  => ['answer_rating' => $answer['answer_rating'] ?? null],
            HrSurveyQuestion::BOOLEAN => ['answer_boolean' => isset($answer['answer_boolean'])
                ? (bool) $answer['answer_boolean'] : null],
            HrSurveyQuestion::SINGLE, HrSurveyQuestion::MULTIPLE => [
                'answer_options' => array_values(array_filter((array) ($answer['answer_options'] ?? []))) ?: null,
            ],
            default => ['answer_text' => $answer['answer_text'] ?? null],
        };

        return array_merge($blank, $filled);
    }

    private function syncQuestions(HrExitQuestionnaire $questionnaire, array $questions, int $tenantId): void
    {
        $keptIds = [];

        foreach (array_values($questions) as $i => $q) {
            if (blank($q['question_text'] ?? null)) {
                continue;
            }

            $type = in_array($q['question_type'] ?? '', HrExitQuestionnaireQuestion::TYPES, true)
                ? $q['question_type'] : HrSurveyQuestion::TEXT;

            // A choice question with no options can never be answered.
            if (in_array($type, HrExitQuestionnaireQuestion::CHOICE_TYPES, true)
                && count(array_filter((array) ($q['options'] ?? []))) < 1) {
                throw new BusinessException(
                    'Add at least one option to "'.$q['question_text'].'" — a choice question with no options cannot be answered.', 422
                );
            }

            $attributes = [
                'tenant_id'        => $tenantId,
                'questionnaire_id' => $questionnaire->id,
                'question_text'    => $q['question_text'],
                'question_type'    => $type,
                'options'          => in_array($type, HrExitQuestionnaireQuestion::CHOICE_TYPES, true)
                    ? array_values(array_filter((array) ($q['options'] ?? []))) : null,
                'rating_max'       => $type === HrSurveyQuestion::RATING ? (int) ($q['rating_max'] ?? 5) : null,
                'is_required'      => (bool) ($q['is_required'] ?? false),
                'sort_order'       => $q['sort_order'] ?? $i,
            ];

            // Scoped by questionnaire_id as well as id, so a caller cannot move
            // another template's question into this one by passing its id.
            $existing = ! empty($q['id'])
                ? HrExitQuestionnaireQuestion::where('questionnaire_id', $questionnaire->id)->find($q['id'])
                : null;

            $row = $existing
                ? tap($existing)->update($attributes)
                : HrExitQuestionnaireQuestion::create($attributes);

            $keptIds[] = $row->id;
        }

        // Questions removed from the template. Answers already given cascade with
        // them, which is why destroy() refuses to delete a template that has been
        // used at all — this path only runs while a template is still being built.
        HrExitQuestionnaireQuestion::where('questionnaire_id', $questionnaire->id)
            ->whereNotIn('id', $keptIds ?: [0])->delete();
    }

    private function present(HrExitQuestionnaire $q): array
    {
        return [
            'id' => $q->id, 'name' => $q->name, 'code' => $q->code,
            'description' => $q->description,
            'exit_type_id' => $q->exit_type_id, 'exit_type' => $q->exitType?->name,
            'is_default' => (bool) $q->is_default, 'is_active' => (bool) $q->is_active,
            'question_count' => $q->questions->count(),
            'questions' => $q->questions->map(fn ($x) => [
                'id' => $x->id, 'question_text' => $x->question_text,
                'question_type' => $x->question_type, 'options' => $x->options,
                'rating_max' => $x->rating_max, 'is_required' => (bool) $x->is_required,
                'sort_order' => (int) $x->sort_order,
            ])->values()->all(),
        ];
    }

    private function find(int $id, int $tenantId): HrExitQuestionnaire
    {
        $questionnaire = HrExitQuestionnaire::forTenant($tenantId)->with(['questions', 'exitType'])->find($id);

        if (! $questionnaire) {
            throw new BusinessException('Exit questionnaire not found', 404);
        }

        return $questionnaire;
    }
}
