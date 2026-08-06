<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrInterviewQuestion;
use App\Models\Hr\HrInterviewQuestionSet;
use App\Models\Hr\HrInterviewQuestionSetItem;
use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrInterviewRoundQuestion;
use App\Models\User;
use App\Support\Hr\SkillMatcher;
use Illuminate\Support\Facades\DB;

/**
 * #10 part 4 — question sets, and attaching them to an interview round.
 *
 * The interview workflow itself is untouched: rounds are still created,
 * scheduled and completed by InterviewService, and a round with no questions
 * behaves exactly as every round did before this existed. This only adds what is
 * asked in the room and what the interviewer scored.
 */
class InterviewQuestionSetService
{
    public function __construct(private InterviewQuestionBankService $bank)
    {
    }

    /* ── Sets ─────────────────────────────────────────────────────────── */

    public function list(int $tenantId, array $filters = []): array
    {
        $query = HrInterviewQuestionSet::forTenant($tenantId)
            ->with(['items.question:id,question_text,question_type,difficulty,marks', 'designation:id,name']);

        if (! empty($filters['designation_id']) && $filters['designation_id'] !== 'All') {
            $query->where('designation_id', $filters['designation_id']);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== 'All' && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->orderBy('name')->get()->map(fn ($s) => $this->presentSet($s))->all();
    }

    public function saveSet(array $data, int $tenantId, ?User $actor = null): array
    {
        if (blank($data['name'] ?? null)) {
            throw new BusinessException('Give the question set a name', 422);
        }

        return DB::transaction(function () use ($data, $tenantId, $actor) {
            $set = ! empty($data['id'])
                ? $this->findSet((int) $data['id'], $tenantId)
                : new HrInterviewQuestionSet(['tenant_id' => $tenantId, 'created_by' => $actor?->id]);

            $set->fill([
                'tenant_id'      => $tenantId,
                'name'           => $data['name'],
                'description'    => $data['description'] ?? null,
                // `?? null` first: a partial payload legitimately omits this key,
                // and `?:` on a missing key is a fatal rather than a default.
                'designation_id' => ($data['designation_id'] ?? null) ?: null,
                'round_name'     => $data['round_name'] ?? null,
                'is_active'      => (bool) ($data['is_active'] ?? true),
                'updated_by'     => $actor?->id,
            ])->save();

            if (array_key_exists('question_ids', $data)) {
                $this->syncItems($set, (array) $data['question_ids'], $tenantId);
            }

            $set->recordAudit(empty($data['id']) ? 'Question set created' : 'Question set updated', $actor);

            return $this->presentSet($set->fresh(['items.question', 'designation']));
        });
    }

    public function destroySet(int $id, int $tenantId, ?User $actor = null): void
    {
        $set = $this->findSet($id, $tenantId);
        $set->recordAudit('Question set deleted', $actor);
        // Only the set is removed. Its items cascade, but the bank questions
        // themselves are shared and stay exactly where they are.
        $set->delete();
    }

    private function syncItems(HrInterviewQuestionSet $set, array $questionIds, int $tenantId): void
    {
        $valid = HrInterviewQuestion::forTenant($tenantId)
            ->whereIn('id', array_filter($questionIds))->pluck('id')->all();

        HrInterviewQuestionSetItem::where('set_id', $set->id)->delete();

        foreach (array_values($valid) as $i => $questionId) {
            HrInterviewQuestionSetItem::create([
                'tenant_id' => $tenantId, 'set_id' => $set->id,
                'question_id' => $questionId, 'sort_order' => $i,
            ]);
        }
    }

    /* ── Round integration ────────────────────────────────────────────── */

    /**
     * Attach questions to a round — from a set, an explicit list, or at random.
     *
     * @param  array  $input  ['set_id'] | ['question_ids' => []] |
     *                        ['random' => ['count', 'difficulty', 'types', 'skills',
     *                         'designation_id', 'experience']]
     */
    public function attach(HrInterviewRound $round, array $input, int $tenantId, ?User $actor = null): array
    {
        $this->assertTenant($round, $tenantId);

        [$questions, $mode] = $this->resolveQuestions($input, $tenantId);

        if ($questions->isEmpty()) {
            throw new BusinessException(
                ($mode === 'random')
                    ? 'No active questions match those criteria — widen the filters or add questions to the bank.'
                    : 'Select at least one question to attach.', 422
            );
        }

        return DB::transaction(function () use ($round, $questions, $mode, $tenantId, $actor) {
            $existing = HrInterviewRoundQuestion::where('interview_round_id', $round->id)
                ->pluck('question_id')->all();
            $nextOrder = (int) HrInterviewRoundQuestion::where('interview_round_id', $round->id)->max('sort_order');

            foreach ($questions as $q) {
                // Attaching twice must not wipe an evaluation already recorded
                // against the first attachment.
                if (in_array($q->id, $existing, true)) {
                    continue;
                }

                HrInterviewRoundQuestion::create([
                    'tenant_id'             => $tenantId,
                    'interview_round_id'    => $round->id,
                    'question_id'           => $q->id,
                    'question_text_snapshot' => $q->question_text,
                    'question_type'         => $q->question_type,
                    'marks'                 => (float) $q->marks,
                    'selection_mode'        => $mode,
                    'sort_order'            => ++$nextOrder,
                ]);
            }

            $round->recordAudit('Interview questions attached', $actor, null,
                ['mode' => $mode, 'count' => $questions->count()]);

            return $this->forRound($round, $tenantId);
        });
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: string} */
    private function resolveQuestions(array $input, int $tenantId): array
    {
        if (! empty($input['set_id'])) {
            $set = $this->findSet((int) $input['set_id'], $tenantId);

            return [$set->items->map(fn ($i) => $i->question)->filter(), 'manual'];
        }

        if (! empty($input['question_ids'])) {
            return [
                HrInterviewQuestion::forTenant($tenantId)
                    ->whereIn('id', (array) $input['question_ids'])->get(),
                'manual',
            ];
        }

        if (! empty($input['random'])) {
            return [$this->randomPick((array) $input['random'], $tenantId), 'random'];
        }

        throw new BusinessException('Choose a set, some questions, or random selection criteria', 422);
    }

    /**
     * Random selection from the bank, honouring the same facets as search.
     *
     * Inactive questions are never picked — deactivating one is how a tenant
     * retires it, and a random pick that resurrected it would defeat that.
     */
    private function randomPick(array $criteria, int $tenantId)
    {
        $count = max(1, min(50, (int) ($criteria['count'] ?? 5)));

        $query = HrInterviewQuestion::forTenant($tenantId)->where('is_active', true);

        if (! empty($criteria['difficulty']) && $criteria['difficulty'] !== 'All') {
            $query->where('difficulty', $criteria['difficulty']);
        }
        if (! empty($criteria['types'])) {
            $query->whereIn('question_type', (array) $criteria['types']);
        }
        if (! empty($criteria['designation_id']) && $criteria['designation_id'] !== 'All') {
            $query->where('designation_id', $criteria['designation_id']);
        }
        if (isset($criteria['experience']) && $criteria['experience'] !== '' && $criteria['experience'] !== null) {
            $years = (float) $criteria['experience'];
            $query->where(fn ($q) => $q->whereNull('experience_min')->orWhere('experience_min', '<=', $years))
                ->where(fn ($q) => $q->whereNull('experience_max')->orWhere('experience_max', '>=', $years));
        }

        $pool = $query->get();

        // Skills live in JSON, so they are filtered in PHP for the same reason the
        // bank search does it — a LIKE would match "java" inside "javascript".
        if (! empty($criteria['skills'])) {
            $wanted = SkillMatcher::clean((array) $criteria['skills']);
            $pool = $pool->filter(fn ($q) => array_intersect($wanted, SkillMatcher::clean($q->skills ?: [])) !== []);
        }

        return $pool->shuffle()->take($count)->values();
    }

    /** Questions asked in one round, with whatever the interviewer has scored. */
    public function forRound(HrInterviewRound $round, int $tenantId): array
    {
        $this->assertTenant($round, $tenantId);

        $rows = HrInterviewRoundQuestion::where('interview_round_id', $round->id)
            ->with('question:id,options,expected_answer,skills,category')
            ->orderBy('sort_order')->get();

        $scored = $rows->whereNotNull('score');

        return [
            'questions' => $rows->map(fn ($r) => [
                'id' => $r->id, 'question_id' => $r->question_id,
                'question_text' => $r->question_text_snapshot,
                'question_type' => $r->question_type,
                'marks' => (float) $r->marks,
                'score' => $r->score !== null ? (float) $r->score : null,
                'answer_notes' => $r->answer_notes,
                'selected_options' => $r->selected_options ?: [],
                'is_correct' => $r->is_correct,
                'selection_mode' => $r->selection_mode,
                // The reference answer is what makes two interviewers score alike,
                // so it travels with the question rather than living in the bank UI.
                'expected_answer' => $r->question?->expected_answer,
                'options' => $r->question?->options ?: [],
                'category' => $r->question?->category,
                'skills' => $r->question?->skills ?: [],
            ])->all(),
            'summary' => [
                'total'        => $rows->count(),
                'evaluated'    => $scored->count(),
                'total_marks'  => round((float) $rows->sum('marks'), 2),
                'total_score'  => round((float) $scored->sum('score'), 2),
                // Percentage over what has actually been scored, not the whole
                // paper — otherwise a half-finished evaluation reads as a low one.
                'percent'      => $scored->sum('marks') > 0
                    ? round($scored->sum('score') / $scored->sum('marks') * 100, 1) : null,
            ],
        ];
    }

    /** Record the interviewer's evaluation of the questions they asked. */
    public function evaluate(HrInterviewRound $round, array $answers, int $tenantId, ?User $actor = null): array
    {
        $this->assertTenant($round, $tenantId);

        DB::transaction(function () use ($round, $answers, $tenantId) {
            $rows = HrInterviewRoundQuestion::where('interview_round_id', $round->id)
                ->where('tenant_id', $tenantId)->with('question')->get()->keyBy('id');

            foreach ($answers as $answer) {
                $row = $rows->get($answer['id'] ?? 0);

                if (! $row) {
                    continue;
                }

                $update = [
                    'answer_notes' => $answer['answer_notes'] ?? $row->answer_notes,
                ];

                if (array_key_exists('score', $answer)) {
                    $score = $answer['score'] === null || $answer['score'] === '' ? null : (float) $answer['score'];

                    // A score above the marks available would corrupt the round
                    // percentage, so it is capped rather than accepted.
                    if ($score !== null) {
                        $score = max(0, min($score, (float) $row->marks ?: $score));
                    }
                    $update['score'] = $score;
                }

                if (array_key_exists('selected_options', $answer)
                    && $row->question_type === HrInterviewQuestion::MCQ) {
                    $selected = array_values((array) $answer['selected_options']);
                    $correct  = $row->question?->correctOptions() ?? [];

                    $update['selected_options'] = $selected;
                    // Exact set match: picking two of three correct answers is not
                    // a correct answer to a multi-correct question.
                    $update['is_correct'] = $correct !== []
                        && count($selected) === count($correct)
                        && array_diff($correct, $selected) === [];

                    // An unscored MCQ scores itself from the answer key; an
                    // interviewer who typed a score keeps theirs.
                    if (! array_key_exists('score', $answer)) {
                        $update['score'] = $update['is_correct'] ? (float) $row->marks : 0.0;
                    }
                }

                $row->update($update);
            }
        });

        $round->recordAudit('Interview questions evaluated', $actor, null,
            ['answered' => count($answers)]);

        return $this->forRound($round, $tenantId);
    }

    public function detach(HrInterviewRound $round, int $roundQuestionId, int $tenantId, ?User $actor = null): array
    {
        $this->assertTenant($round, $tenantId);

        HrInterviewRoundQuestion::where('interview_round_id', $round->id)
            ->where('tenant_id', $tenantId)->where('id', $roundQuestionId)->delete();

        $round->recordAudit('Interview question removed', $actor);

        return $this->forRound($round, $tenantId);
    }

    /* ── Internals ────────────────────────────────────────────────────── */

    private function presentSet(HrInterviewQuestionSet $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'description' => $s->description,
            'designation_id' => $s->designation_id, 'designation' => $s->designation?->name,
            'round_name' => $s->round_name, 'is_active' => (bool) $s->is_active,
            'question_count' => $s->items->count(),
            'total_marks' => round((float) $s->items->sum(fn ($i) => $i->marks()), 2),
            'questions' => $s->items->map(fn ($i) => [
                'id' => $i->question_id,
                'question_text' => $i->question?->question_text,
                'question_type' => $i->question?->question_type,
                'difficulty' => $i->question?->difficulty,
                'marks' => $i->marks(),
            ])->values()->all(),
        ];
    }

    private function findSet(int $id, int $tenantId): HrInterviewQuestionSet
    {
        $set = HrInterviewQuestionSet::forTenant($tenantId)->with(['items.question', 'designation'])->find($id);

        if (! $set) {
            throw new BusinessException('Question set not found', 404);
        }

        return $set;
    }

    private function assertTenant(HrInterviewRound $round, int $tenantId): void
    {
        if ((int) $round->tenant_id !== $tenantId) {
            throw new BusinessException('Interview round not found', 404);
        }
    }
}
