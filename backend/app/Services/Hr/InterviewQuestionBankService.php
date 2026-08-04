<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrInterviewQuestion;
use App\Models\User;
use App\Support\Hr\SkillMatcher;
use Illuminate\Support\Facades\DB;

/**
 * Review comment #10 — the interview question bank.
 *
 * Reuses rather than rebuilds:
 *   - `hr_designations` for role mapping — the same master the job posting and
 *     the employee record use, so "Senior Engineer" means one thing.
 *   - SkillMatcher::clean() for the skill vocabulary (#43), so a question tagged
 *     "React" is found by a job requiring "react".
 *
 * Deliberately NOT reused: the training quiz engine. See the migration for why.
 */
class InterviewQuestionBankService
{
    /** Search and filter — every facet the comment asked for. */
    public function list(int $tenantId, array $filters = []): array
    {
        $query = HrInterviewQuestion::forTenant($tenantId)->with('designation:id,name');

        foreach (['question_type', 'difficulty', 'category', 'designation_id', 'source'] as $key) {
            if (! empty($filters[$key]) && $filters[$key] !== 'All') {
                $query->where($key, $filters[$key]);
            }
        }

        // Active/Inactive. Checked with isset, not empty(): "0" is a real choice
        // and empty('0') is true, which would silently show active questions.
        if (isset($filters['is_active']) && $filters['is_active'] !== 'All' && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('question_text', 'like', $term)
                ->orWhere('category', 'like', $term)
                ->orWhere('expected_answer', 'like', $term));
        }

        // Experience: find questions whose band CONTAINS the candidate's years.
        // A null bound means open-ended, so it must not exclude anyone.
        if (isset($filters['experience']) && $filters['experience'] !== '' && $filters['experience'] !== null) {
            $years = (float) $filters['experience'];
            $query->where(fn ($q) => $q->whereNull('experience_min')->orWhere('experience_min', '<=', $years))
                ->where(fn ($q) => $q->whereNull('experience_max')->orWhere('experience_max', '>=', $years));
        }

        $rows = $query->orderByDesc('id')->get();

        // Skills and tags are JSON, so they are matched in PHP rather than with a
        // LIKE against serialised JSON, which would match "java" inside
        // "javascript" and is not portable across SQLite and MySQL.
        if (! empty($filters['skills'])) {
            $wanted = SkillMatcher::clean(is_array($filters['skills'])
                ? $filters['skills'] : explode(',', (string) $filters['skills']));
            $rows = $rows->filter(fn ($q) => array_intersect($wanted, SkillMatcher::clean($q->skills ?: [])) !== []);
        }
        if (! empty($filters['tag'])) {
            $tag = mb_strtolower(trim($filters['tag']));
            $rows = $rows->filter(fn ($q) => in_array($tag, array_map('mb_strtolower', $q->tags ?: []), true));
        }

        return $rows->map(fn ($q) => $this->present($q))->values()->all();
    }

    public function save(array $data, int $tenantId, ?User $actor = null): array
    {
        if (blank($data['question_text'] ?? null)) {
            throw new BusinessException('The question cannot be blank', 422);
        }

        $type = in_array($data['question_type'] ?? '', HrInterviewQuestion::TYPES, true)
            ? $data['question_type'] : HrInterviewQuestion::SUBJECTIVE;

        $options = $this->prepareOptions($type, $data['options'] ?? []);

        $payload = [
            'tenant_id'       => $tenantId,
            'question_text'   => trim($data['question_text']),
            'question_type'   => $type,
            'category'        => $data['category'] ?? null,
            // `?? null` before the falsy check throughout: a partial payload (an
            // AI draft, or an edit that touches one field) legitimately omits
            // keys, and `?:` on a missing key is a fatal, not a default.
            'designation_id'  => ($data['designation_id'] ?? null) ?: null,
            'skills'          => SkillMatcher::clean($this->toArray($data['skills'] ?? [])) ?: null,
            'tags'            => array_values(array_filter(array_map('trim', $this->toArray($data['tags'] ?? [])))) ?: null,
            'difficulty'      => in_array($data['difficulty'] ?? '', HrInterviewQuestion::DIFFICULTIES, true)
                ? $data['difficulty'] : 'medium',
            'experience_min'  => $this->nullableNumber($data['experience_min'] ?? null),
            'experience_max'  => $this->nullableNumber($data['experience_max'] ?? null),
            'options'         => $options,
            'expected_answer' => $data['expected_answer'] ?? null,
            'marks'           => (float) ($data['marks'] ?? 0),
            'time_limit_seconds' => ($data['time_limit_seconds'] ?? null) ?: null,
            'is_active'       => (bool) ($data['is_active'] ?? true),
            'updated_by'      => $actor?->id,
        ];

        if ($payload['experience_min'] !== null && $payload['experience_max'] !== null
            && (float) $payload['experience_max'] < (float) $payload['experience_min']) {
            throw new BusinessException('The experience range ends before it begins', 422);
        }

        $question = ! empty($data['id'])
            ? $this->find((int) $data['id'], $tenantId)
            : null;

        if ($question) {
            // A manually edited AI question stays flagged as AI in origin but the
            // edit is recorded — #10 asks for "manual edit" of generated questions,
            // and pretending a human wrote it would lose that provenance.
            $question->update($payload);
            $question->recordAudit('Interview question updated', $actor);

            return $this->present($question->fresh('designation'));
        }

        $question = HrInterviewQuestion::create($payload + [
            'source' => $data['source'] ?? 'manual',
            'ai_meta' => $data['ai_meta'] ?? null,
            'created_by' => $actor?->id,
        ]);
        $question->recordAudit('Interview question created', $actor, null, ['source' => $question->source]);

        return $this->present($question->fresh('designation'));
    }

    public function toggle(int $id, int $tenantId, ?User $actor = null): array
    {
        $question = $this->find($id, $tenantId);
        $question->update(['is_active' => ! $question->is_active]);
        $question->recordAudit($question->is_active ? 'Interview question activated' : 'Interview question deactivated', $actor);

        return $this->present($question->fresh('designation'));
    }

    public function destroy(int $id, int $tenantId, ?User $actor = null): void
    {
        $question = $this->find($id, $tenantId);

        // Asked questions are part of an interview record. Deleting one would
        // cascade the evaluation away, so it is retired instead.
        $asked = DB::table('hr_interview_round_questions')->where('question_id', $id)->exists();

        if ($asked) {
            $question->update(['is_active' => false]);
            $question->recordAudit('Interview question retired (already asked)', $actor);

            return;
        }

        $question->recordAudit('Interview question deleted', $actor);
        $question->delete();
    }

    /** Bulk save — how a batch of AI-generated questions reaches the bank. */
    public function saveMany(array $questions, int $tenantId, ?User $actor = null): array
    {
        return DB::transaction(fn () => array_map(
            fn ($q) => $this->save($q, $tenantId, $actor),
            array_values($questions)
        ));
    }

    /** Distinct categories in use — powers the filter without a category master. */
    public function categories(int $tenantId): array
    {
        return HrInterviewQuestion::forTenant($tenantId)
            ->whereNotNull('category')->distinct()->orderBy('category')
            ->pluck('category')->values()->all();
    }

    public function present(HrInterviewQuestion $q): array
    {
        return [
            'id' => $q->id, 'question_text' => $q->question_text, 'question_type' => $q->question_type,
            'category' => $q->category, 'designation_id' => $q->designation_id,
            'designation' => $q->designation?->name,
            'skills' => $q->skills ?: [], 'tags' => $q->tags ?: [],
            'difficulty' => $q->difficulty,
            'experience_min' => $q->experience_min !== null ? (float) $q->experience_min : null,
            'experience_max' => $q->experience_max !== null ? (float) $q->experience_max : null,
            'options' => $q->options ?: [],
            'expected_answer' => $q->expected_answer,
            'marks' => (float) $q->marks,
            'time_limit_seconds' => $q->time_limit_seconds,
            'is_active' => (bool) $q->is_active,
            'source' => $q->source, 'ai_meta' => $q->ai_meta,
        ];
    }

    /* ── Internals ────────────────────────────────────────────────────── */

    /**
     * Options belong to MCQ alone, and an MCQ needs an answer key.
     *
     * More than one option may be correct — that is the comment's "multiple
     * correct answers where applicable", handled by the data rather than by a
     * second question type.
     */
    private function prepareOptions(string $type, $options): ?array
    {
        if ($type !== HrInterviewQuestion::MCQ) {
            return null;
        }

        $clean = collect(is_array($options) ? $options : [])
            ->map(fn ($o) => is_array($o)
                ? ['text' => trim((string) ($o['text'] ?? '')), 'is_correct' => (bool) ($o['is_correct'] ?? false)]
                : ['text' => trim((string) $o), 'is_correct' => false])
            ->filter(fn ($o) => $o['text'] !== '')
            ->values();

        if ($clean->count() < 2) {
            throw new BusinessException('A multiple-choice question needs at least two options', 422);
        }
        if ($clean->every(fn ($o) => ! $o['is_correct'])) {
            throw new BusinessException('Mark at least one option as correct, or the question can never be scored', 422);
        }

        return $clean->all();
    }

    /** '' and null both mean "no bound"; 0 is a real value and must survive. */
    private function nullableNumber($value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    private function toArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return array_filter(array_map('trim', explode(',', (string) $value)));
    }

    private function find(int $id, int $tenantId): HrInterviewQuestion
    {
        $question = HrInterviewQuestion::forTenant($tenantId)->find($id);

        if (! $question) {
            throw new BusinessException('Interview question not found', 404);
        }

        return $question;
    }
}
