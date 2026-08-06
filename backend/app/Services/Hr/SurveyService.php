<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrSurvey;
use App\Models\Hr\HrSurveyAnswer;
use App\Models\Hr\HrSurveyCategory;
use App\Models\Hr\HrSurveyQuestion;
use App\Models\Hr\HrSurveyResponse;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Review comment #26 — the Employee Survey module.
 *
 * Survey master, categories, question builder, scheduling, responses.
 * Analytics live in SurveyReportService.
 *
 * ANONYMITY. On an anonymous survey the response stores NO employee_id, and
 * nothing derived from one. That means the system genuinely cannot tell who
 * answered — which is the only version of "anonymous" worth offering. The cost
 * is real and accepted: duplicate submissions cannot be prevented by identity on
 * an anonymous survey, so `allow_multiple_responses` is forced on for them rather
 * than pretending to enforce something it cannot.
 *
 * `is_anonymous` is IMMUTABLE once a response exists. Turning it off afterwards
 * would expose people who answered believing otherwise; turning it on would leave
 * already-identified responses in place while claiming anonymity.
 */
class SurveyService
{
    /* ══ Categories ══════════════════════════════════════════════════ */

    public function categories(int $tenantId, array $filters = []): array
    {
        return HrSurveyCategory::forTenant($tenantId)
            ->when(isset($filters['is_active']) && $filters['is_active'] !== '',
                fn ($q) => $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->withCount('surveys')
            ->orderBy('name')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'colour' => $c->colour,
                'description' => $c->description, 'is_active' => (bool) $c->is_active,
                'survey_count' => $c->surveys_count,
            ])->all();
    }

    public function saveCategory(?int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $attrs = array_filter([
            'name' => $data['name'] ?? null, 'code' => $data['code'] ?? null,
            'colour' => $data['colour'] ?? null, 'description' => $data['description'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('is_active', $data)) {
            $attrs['is_active'] = (bool) $data['is_active'];
        }

        if ($id) {
            $category = HrSurveyCategory::forTenant($tenantId)->find($id);
            if (! $category) {
                throw new BusinessException('Survey category not found', 404);
            }
            $category->update($attrs + ['updated_by' => $actor?->id]);
        } else {
            $category = HrSurveyCategory::create($attrs + ['tenant_id' => $tenantId, 'created_by' => $actor?->id]);
        }

        $category->recordAudit($id ? 'Survey Category Updated' : 'Survey Category Created', $actor);

        return ['id' => $category->id, 'name' => $category->name, 'is_active' => (bool) $category->is_active];
    }

    public function deleteCategory(int $id, int $tenantId, ?User $actor = null): void
    {
        $category = HrSurveyCategory::forTenant($tenantId)->withCount('surveys')->find($id);
        if (! $category) {
            throw new BusinessException('Survey category not found', 404);
        }
        if ($category->surveys_count > 0) {
            throw new BusinessException('Surveys exist under this category. Deactivate it instead of deleting it.');
        }

        $category->recordAudit('Survey Category Deleted', $actor);
        $category->delete();
    }

    /* ══ Surveys ═════════════════════════════════════════════════════ */

    public function list(int $tenantId, array $filters = []): array
    {
        $this->refreshStatuses($tenantId);

        $q = HrSurvey::forTenant($tenantId)->with('category:id,name,colour')->withCount([
            'questions',
            'responses as response_count' => fn ($r) => $r->where('status', HrSurveyResponse::SUBMITTED),
        ]);

        foreach (['status' => 'status', 'category_id' => 'category_id', 'audience' => 'audience'] as $key => $col) {
            if (! empty($filters[$key])) {
                $q->where($col, $filters[$key]);
            }
        }
        if (! empty($filters['search'])) {
            $q->where('title', 'like', '%'.$filters['search'].'%');
        }

        return $q->orderByDesc('id')->get()->map(fn ($s) => $this->present($s))->all();
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId), withQuestions: true);
    }

    public function save(?int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $survey = DB::transaction(function () use ($id, $data, $tenantId, $actor) {
            $attrs = array_filter([
                'category_id'   => $data['category_id'] ?? null,
                'title'         => $data['title'] ?? null,
                'description'   => $data['description'] ?? null,
                'instructions'  => $data['instructions'] ?? null,
                'starts_at'     => $data['starts_at'] ?? null,
                'ends_at'       => $data['ends_at'] ?? null,
                'audience'      => $data['audience'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'designation_id' => $data['designation_id'] ?? null,
            ], fn ($v) => $v !== null);

            if ($id) {
                $survey = $this->find($id, $tenantId);

                // NOT assertEditable() here: only the QUESTIONS are frozen once
                // responses exist. Gating the whole save would also block fixing a
                // typo in the title, which harms nobody — syncQuestions() applies
                // the lock where it actually belongs.

                // Anonymity cannot change once anyone has answered — see class note.
                if (array_key_exists('is_anonymous', $data)
                    && (bool) $data['is_anonymous'] !== (bool) $survey->is_anonymous
                    && $survey->responses()->exists()) {
                    throw new BusinessException('Anonymity cannot be changed once responses have been collected.');
                }
                if (array_key_exists('is_anonymous', $data)) {
                    $attrs['is_anonymous'] = (bool) $data['is_anonymous'];
                }
                if (array_key_exists('allow_multiple_responses', $data)) {
                    $attrs['allow_multiple_responses'] = (bool) $data['allow_multiple_responses'];
                }
                $survey->update($attrs + ['updated_by' => $actor?->id]);
            } else {
                $attrs['is_anonymous'] = (bool) ($data['is_anonymous'] ?? false);
                $attrs['allow_multiple_responses'] = (bool) ($data['allow_multiple_responses'] ?? false);
                $attrs['status'] = HrSurvey::DRAFT;
                $survey = HrSurvey::create($attrs + ['tenant_id' => $tenantId, 'created_by' => $actor?->id]);
            }

            // An anonymous survey cannot enforce one-response-per-person, because it
            // does not know who anyone is. Saying so in data beats pretending.
            if ($survey->is_anonymous && ! $survey->allow_multiple_responses) {
                $survey->update(['allow_multiple_responses' => true]);
            }

            if (array_key_exists('questions', $data)) {
                $this->syncQuestions($survey, $data['questions'] ?? [], $tenantId);
            }

            return $survey;
        });

        $survey->recordAudit($id ? 'Survey Updated' : 'Survey Created', $actor, null, ['title' => $survey->title]);

        return $this->present($survey->fresh(['questions', 'category']), withQuestions: true);
    }

    /** Publish: Draft → Active now, or Scheduled when it starts in the future. */
    public function publish(int $id, int $tenantId, ?User $actor = null): array
    {
        $survey = $this->find($id, $tenantId);

        if (! in_array($survey->status, [HrSurvey::DRAFT, HrSurvey::SCHEDULED], true)) {
            throw new BusinessException('Only a draft or scheduled survey can be published.');
        }
        if ($survey->questions()->count() === 0) {
            throw new BusinessException('Add at least one question before publishing.');
        }

        $scheduled = $survey->starts_at && Carbon::now()->lt($survey->starts_at);

        $survey->update([
            'status'       => $scheduled ? HrSurvey::SCHEDULED : HrSurvey::ACTIVE,
            'published_at' => now(),
            'updated_by'   => $actor?->id,
        ]);
        $survey->recordAudit($scheduled ? 'Survey Scheduled' : 'Survey Published', $actor);
        Log::channel('hr')->info('Survey published', ['tenant_id' => $tenantId, 'survey_id' => $survey->id]);

        return $this->present($survey->fresh(['questions', 'category']), withQuestions: true);
    }

    public function close(int $id, int $tenantId, ?User $actor = null): array
    {
        $survey = $this->find($id, $tenantId);

        if (! in_array($survey->status, [HrSurvey::ACTIVE, HrSurvey::SCHEDULED], true)) {
            throw new BusinessException('Only an active or scheduled survey can be closed.');
        }

        $survey->update(['status' => HrSurvey::CLOSED, 'closed_at' => now(), 'updated_by' => $actor?->id]);
        $survey->recordAudit('Survey Closed', $actor);

        return $this->present($survey->fresh(['questions', 'category']), withQuestions: true);
    }

    public function delete(int $id, int $tenantId, ?User $actor = null): void
    {
        $survey = $this->find($id, $tenantId);

        // Deleting a survey with responses would destroy the answers people gave.
        if ($survey->responses()->exists()) {
            throw new BusinessException('This survey has responses. Close or archive it instead of deleting it.');
        }

        $survey->recordAudit('Survey Deleted', $actor, null, ['title' => $survey->title]);
        $survey->questions()->delete();
        $survey->delete();
    }

    /**
     * Move Scheduled → Active and Active → Closed when their dates pass.
     *
     * Run on read rather than by a scheduler: a survey nobody is looking at does
     * not need to change state on time, and this keeps the module free of a cron
     * dependency the rest of HR does not have.
     */
    public function refreshStatuses(int $tenantId): void
    {
        $now = Carbon::now();

        HrSurvey::forTenant($tenantId)
            ->where('status', HrSurvey::SCHEDULED)
            ->whereNotNull('starts_at')->where('starts_at', '<=', $now)
            ->update(['status' => HrSurvey::ACTIVE]);

        HrSurvey::forTenant($tenantId)
            ->whereIn('status', [HrSurvey::ACTIVE, HrSurvey::SCHEDULED])
            ->whereNotNull('ends_at')->where('ends_at', '<', $now)
            ->update(['status' => HrSurvey::CLOSED, 'closed_at' => $now]);
    }

    /* ══ Responses ═══════════════════════════════════════════════════ */

    /** Surveys this employee can currently answer. */
    public function availableFor(int $employeeId, int $tenantId): array
    {
        $this->refreshStatuses($tenantId);

        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        return HrSurvey::forTenant($tenantId)
            ->where('status', HrSurvey::ACTIVE)
            ->with('category:id,name,colour')->withCount('questions')
            ->get()
            ->filter(fn ($s) => $s->isOpen() && $this->inAudience($s, $employee))
            ->filter(function ($s) use ($employeeId, $tenantId) {
                if ($s->allow_multiple_responses) {
                    return true;
                }

                return ! HrSurveyResponse::forTenant($tenantId)
                    ->where('survey_id', $s->id)->where('employee_id', $employeeId)
                    ->where('status', HrSurveyResponse::SUBMITTED)->exists();
            })
            ->map(fn ($s) => $this->present($s))->values()->all();
    }

    /**
     * Submit a response.
     *
     * The employee id is used to check eligibility and then DISCARDED for an
     * anonymous survey — it is never written. Department is snapshotted either
     * way so department analytics survive both anonymity and a later transfer.
     */
    public function submitResponse(int $surveyId, int $employeeId, array $answers, int $tenantId): array
    {
        $this->refreshStatuses($tenantId);

        $survey = $this->find($surveyId, $tenantId);
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);

        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }
        if (! $survey->isOpen()) {
            throw new BusinessException('This survey is not open for responses.');
        }
        if (! $this->inAudience($survey, $employee)) {
            throw new BusinessException('This survey is not addressed to this employee.');
        }

        if (! $survey->allow_multiple_responses) {
            $already = HrSurveyResponse::forTenant($tenantId)
                ->where('survey_id', $surveyId)->where('employee_id', $employeeId)
                ->where('status', HrSurveyResponse::SUBMITTED)->exists();
            if ($already) {
                throw new BusinessException('You have already responded to this survey.');
            }
        }

        $questions = $survey->questions()->get()->keyBy('id');
        $byQuestion = collect($answers)->keyBy(fn ($a) => (int) ($a['question_id'] ?? 0));

        // Required questions are checked BEFORE anything is written, so a partial
        // response never lands in the table.
        foreach ($questions as $question) {
            if ($question->is_required && ! $this->hasContent($byQuestion[$question->id] ?? null, $question)) {
                throw new BusinessException('Please answer: '.$question->question_text);
            }
        }

        $response = DB::transaction(function () use ($survey, $employee, $employeeId, $questions, $byQuestion, $tenantId) {
            $response = HrSurveyResponse::create([
                'tenant_id'   => $tenantId,
                'survey_id'   => $survey->id,
                // The entire anonymity guarantee is this line.
                'employee_id' => $survey->is_anonymous ? null : $employeeId,
                'department'  => $employee->department,
                'designation' => $employee->designation,
                'status'      => HrSurveyResponse::SUBMITTED,
                'submitted_at' => now(),
            ]);

            foreach ($questions as $question) {
                $given = $byQuestion[$question->id] ?? null;
                if ($given === null) {
                    continue;
                }
                HrSurveyAnswer::create([
                    'tenant_id'        => $tenantId,
                    'response_id'      => $response->id,
                    'question_id'      => $question->id,
                    'answer_text'      => $question->question_type === HrSurveyQuestion::TEXT ? ($given['answer_text'] ?? null) : null,
                    'answer_number'    => $question->question_type === HrSurveyQuestion::RATING ? ($given['answer_number'] ?? null) : null,
                    'answer_boolean'   => $question->question_type === HrSurveyQuestion::BOOLEAN ? ($given['answer_boolean'] ?? null) : null,
                    'selected_options' => in_array($question->question_type, HrSurveyQuestion::CHOICE_TYPES, true)
                        ? array_values((array) ($given['selected_options'] ?? [])) : null,
                ]);
            }

            return $response;
        });

        return [
            'id'           => $response->id,
            'survey_id'    => $survey->id,
            'anonymous'    => $survey->is_anonymous,
            'submitted_at' => optional($response->submitted_at)->toIso8601String(),
        ];
    }

    /* ══ Helpers ═════════════════════════════════════════════════════ */

    /** Whether the employee falls in the survey's audience. */
    private function inAudience(HrSurvey $survey, HrEmployee $employee): bool
    {
        return match ($survey->audience) {
            'Department'  => $survey->department_id
                && ((int) $employee->department_id === (int) $survey->department_id),
            'Designation' => $survey->designation_id
                && ((int) $employee->designation_id === (int) $survey->designation_id),
            default       => true,
        };
    }

    /** Whether an answer payload actually contains something for this question. */
    private function hasContent($given, HrSurveyQuestion $question): bool
    {
        if (! is_array($given)) {
            return false;
        }

        return match ($question->question_type) {
            HrSurveyQuestion::TEXT    => trim((string) ($given['answer_text'] ?? '')) !== '',
            HrSurveyQuestion::RATING  => ($given['answer_number'] ?? null) !== null,
            HrSurveyQuestion::BOOLEAN => ($given['answer_boolean'] ?? null) !== null,
            default                   => ! empty($given['selected_options']),
        };
    }

    /** Questions are replaced wholesale — a merge would strand deleted ones. */
    private function syncQuestions(HrSurvey $survey, array $questions, int $tenantId): void
    {
        $this->assertEditable($survey);

        $survey->questions()->delete();

        foreach (array_values($questions) as $i => $q) {
            $type = $q['question_type'] ?? HrSurveyQuestion::TEXT;
            if (! in_array($type, HrSurveyQuestion::TYPES, true)) {
                throw new BusinessException("Unknown question type: {$type}");
            }
            // A choice question with no choices cannot be answered.
            $options = array_values(array_filter(array_map('trim', (array) ($q['options'] ?? []))));
            if (in_array($type, HrSurveyQuestion::CHOICE_TYPES, true) && count($options) < 2) {
                throw new BusinessException('A choice question needs at least two options.');
            }

            HrSurveyQuestion::create([
                'tenant_id'     => $tenantId,
                'survey_id'     => $survey->id,
                'question_text' => $q['question_text'] ?? '',
                'question_type' => $type,
                'options'       => in_array($type, HrSurveyQuestion::CHOICE_TYPES, true) ? $options : null,
                'rating_max'    => $type === HrSurveyQuestion::RATING ? (int) ($q['rating_max'] ?? 5) : null,
                'is_required'   => (bool) ($q['is_required'] ?? false),
                'sort_order'    => $i,
            ]);
        }
    }

    /**
     * Questions may only change while nobody has answered. Editing them afterwards
     * would leave existing answers pointing at questions that no longer exist, or
     * silently re-interpret an answer under a different question.
     */
    private function assertEditable(HrSurvey $survey): void
    {
        if ($survey->responses()->exists()) {
            throw new BusinessException('This survey already has responses — its questions can no longer be changed.');
        }
    }

    private function find(int $id, int $tenantId): HrSurvey
    {
        $survey = HrSurvey::forTenant($tenantId)->with(['questions', 'category'])->find($id);
        if (! $survey) {
            throw new BusinessException('Survey not found', 404);
        }

        return $survey;
    }

    private function present(HrSurvey $s, bool $withQuestions = false): array
    {
        $out = [
            'id'            => $s->id,
            'title'         => $s->title,
            'description'   => $s->description,
            'instructions'  => $s->instructions,
            'category_id'   => $s->category_id,
            'category_name' => $s->category?->name,
            'category_colour' => $s->category?->colour,
            'status'        => $s->status,
            'is_open'       => $s->isOpen(),
            'is_anonymous'  => (bool) $s->is_anonymous,
            'allow_multiple_responses' => (bool) $s->allow_multiple_responses,
            'audience'      => $s->audience,
            'department_id' => $s->department_id,
            'designation_id' => $s->designation_id,
            'starts_at'     => optional($s->starts_at)->toIso8601String(),
            'ends_at'       => optional($s->ends_at)->toIso8601String(),
            'published_at'  => optional($s->published_at)->toIso8601String(),
            'closed_at'     => optional($s->closed_at)->toIso8601String(),
            'question_count' => $s->questions_count ?? ($s->relationLoaded('questions') ? $s->questions->count() : null),
            'response_count' => $s->response_count ?? null,
        ];

        if ($withQuestions) {
            $out['questions'] = $s->questions->map(fn ($q) => [
                'id'            => $q->id,
                'question_text' => $q->question_text,
                'question_type' => $q->question_type,
                'options'       => $q->options ?: [],
                'rating_max'    => $q->rating_max,
                'is_required'   => (bool) $q->is_required,
                'sort_order'    => $q->sort_order,
            ])->all();
        }

        return $out;
    }
}
