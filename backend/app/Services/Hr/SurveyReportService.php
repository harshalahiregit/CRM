<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrSurvey;
use App\Models\Hr\HrSurveyAnswer;
use App\Models\Hr\HrSurveyQuestion;
use App\Models\Hr\HrSurveyResponse;

/**
 * #26 — survey dashboard, analytics, department breakdown, reports and export.
 *
 * THE SUPPRESSION RULE is the important thing in this file.
 *
 * An anonymous survey stores no employee id, but it does store the department —
 * because "Department Responses" was asked for. In a department of two, publishing
 * a per-department breakdown narrows an answer to one of two people, which
 * defeats the anonymity the employee was promised. So department breakdowns on an
 * ANONYMOUS survey are suppressed below MIN_ANONYMOUS_GROUP responses: the group
 * is reported as present, with its answers withheld and a reason given, rather
 * than silently omitted (which would itself leak that the group is small).
 *
 * Named surveys are not suppressed — nothing was promised.
 */
class SurveyReportService
{
    /**
     * Fewest responses a department must have before its anonymous breakdown is
     * published. Four is a judgement, not a standard; it is a constant so it can
     * be argued with in one place.
     */
    public const MIN_ANONYMOUS_GROUP = 4;

    /* ══ Dashboard ═══════════════════════════════════════════════════ */

    public function dashboard(int $tenantId): array
    {
        $surveys = HrSurvey::forTenant($tenantId)->get(['id', 'status', 'is_anonymous']);
        $responses = HrSurveyResponse::forTenant($tenantId)->where('status', HrSurveyResponse::SUBMITTED);

        $byStatus = [];
        foreach (HrSurvey::STATUSES as $status) {
            $byStatus[$status] = $surveys->where('status', $status)->count();
        }

        return [
            'total_surveys'     => $surveys->count(),
            'by_status'         => $byStatus,
            'active_surveys'    => $byStatus[HrSurvey::ACTIVE] ?? 0,
            'anonymous_surveys' => $surveys->where('is_anonymous', true)->count(),
            'total_responses'   => (clone $responses)->count(),
            'responses_last_30_days' => (clone $responses)->where('submitted_at', '>=', now()->subDays(30))->count(),
            // Response rate needs a denominator; active employees is the honest one.
            'active_employees'  => HrEmployee::where('tenant_id', $tenantId)->where('status', 'Active')->count(),
        ];
    }

    /* ══ Per-survey analytics ════════════════════════════════════════ */

    public function analytics(int $surveyId, int $tenantId): array
    {
        $survey = $this->find($surveyId, $tenantId);

        $responses = HrSurveyResponse::forTenant($tenantId)
            ->where('survey_id', $surveyId)->where('status', HrSurveyResponse::SUBMITTED)->get();

        $answers = HrSurveyAnswer::forTenant($tenantId)
            ->whereIn('response_id', $responses->pluck('id'))
            ->get()->groupBy('question_id');

        $eligible = $this->eligibleCount($survey, $tenantId);

        return [
            'survey' => [
                'id' => $survey->id, 'title' => $survey->title, 'status' => $survey->status,
                'is_anonymous' => (bool) $survey->is_anonymous,
            ],
            'response_count'  => $responses->count(),
            'eligible_count'  => $eligible,
            'response_rate'   => $eligible > 0 ? round($responses->count() / $eligible * 100, 1) : null,
            'questions'       => $survey->questions->map(fn ($q) => $this->questionAnalytics($q, $answers[$q->id] ?? collect()))->all(),
            'departments'     => $this->departmentBreakdown($survey, $responses),
        ];
    }

    /** Per-question aggregation, shaped by the question type. */
    private function questionAnalytics(HrSurveyQuestion $question, $answers): array
    {
        $base = [
            'question_id'   => $question->id,
            'question_text' => $question->question_text,
            'question_type' => $question->question_type,
            'answer_count'  => $answers->count(),
        ];

        return match ($question->question_type) {
            HrSurveyQuestion::RATING => $base + [
                'rating_max' => $question->rating_max,
                'average'    => $answers->count() ? round((float) $answers->avg('answer_number'), 2) : null,
                // The full distribution, not just the mean: an average of 3 from
                // all-3s and an average of 3 from half-1s/half-5s are different
                // organisations, and only the distribution shows which.
                'distribution' => $answers->groupBy(fn ($a) => (int) $a->answer_number)
                    ->map->count()->sortKeys()->all(),
            ],
            HrSurveyQuestion::BOOLEAN => $base + [
                'yes' => $answers->where('answer_boolean', true)->count(),
                'no'  => $answers->where('answer_boolean', false)->count(),
            ],
            HrSurveyQuestion::SINGLE, HrSurveyQuestion::MULTIPLE => $base + [
                'options' => collect($question->options ?: [])->map(function ($opt) use ($answers) {
                    $count = $answers->filter(fn ($a) => in_array($opt, (array) $a->selected_options, true))->count();

                    return ['option' => $opt, 'count' => $count];
                })->all(),
            ],
            default => $base + [
                // Free text is listed, never aggregated — there is nothing honest
                // to average, and summarising it would invent findings.
                'responses' => $answers->pluck('answer_text')->filter()->values()->all(),
            ],
        };
    }

    /**
     * Department breakdown, with the suppression rule applied.
     *
     * A suppressed group is still LISTED with its count — omitting it entirely
     * would itself reveal which departments are small.
     */
    private function departmentBreakdown(HrSurvey $survey, $responses): array
    {
        return $responses->groupBy(fn ($r) => $r->department ?: 'Unspecified')
            ->map(function ($group, $department) use ($survey) {
                $count = $group->count();
                $suppressed = $survey->is_anonymous && $count < self::MIN_ANONYMOUS_GROUP;

                return [
                    'department'     => $department,
                    'response_count' => $count,
                    'suppressed'     => $suppressed,
                    'reason'         => $suppressed
                        ? 'Withheld to protect anonymity — fewer than '.self::MIN_ANONYMOUS_GROUP.' responses.'
                        : null,
                ];
            })->values()->sortByDesc('response_count')->values()->all();
    }

    /* ══ Responses list ══════════════════════════════════════════════ */

    /**
     * Individual responses.
     *
     * On an anonymous survey the employee columns are simply absent from the
     * payload — not blanked, not "Anonymous" with an id hiding behind it.
     */
    public function responses(int $surveyId, int $tenantId, array $filters = []): array
    {
        $survey = $this->find($surveyId, $tenantId);

        $q = HrSurveyResponse::forTenant($tenantId)
            ->where('survey_id', $surveyId)
            ->where('status', HrSurveyResponse::SUBMITTED)
            ->with(['answers.question:id,question_text,question_type']);

        if (! $survey->is_anonymous) {
            $q->with('employee:id,name,employee_code');
        }
        if (! empty($filters['department'])) {
            $q->where('department', $filters['department']);
        }

        return $q->orderByDesc('submitted_at')->get()->map(function ($r) use ($survey) {
            $row = [
                'id'           => $r->id,
                'department'   => $r->department,
                'designation'  => $r->designation,
                'submitted_at' => optional($r->submitted_at)->toIso8601String(),
                'answers'      => $r->answers->map(fn ($a) => [
                    'question_id'   => $a->question_id,
                    'question_text' => $a->question?->question_text,
                    'question_type' => $a->question?->question_type,
                    'answer'        => $this->answerValue($a),
                ])->all(),
            ];

            if (! $survey->is_anonymous) {
                $row['employee_id'] = $r->employee_id;
                $row['employee_name'] = $r->employee?->name;
                $row['employee_code'] = $r->employee?->employee_code;
            }

            return $row;
        })->all();
    }

    /* ══ Export ══════════════════════════════════════════════════════ */

    /**
     * CSV rows: one row per response, one column per question.
     *
     * Returns rows rather than a file so the controller owns the HTTP concern,
     * matching how the payroll and leave exports already work.
     */
    public function exportRows(int $surveyId, int $tenantId): array
    {
        $survey = $this->find($surveyId, $tenantId);
        $responses = $this->responses($surveyId, $tenantId);

        $header = $survey->is_anonymous
            ? ['Response #', 'Department', 'Submitted']
            : ['Response #', 'Employee', 'Code', 'Department', 'Submitted'];

        foreach ($survey->questions as $q) {
            $header[] = $q->question_text;
        }

        $rows = [$header];

        foreach ($responses as $i => $r) {
            $byQuestion = collect($r['answers'])->keyBy('question_id');

            $row = $survey->is_anonymous
                ? [$i + 1, $r['department'] ?? '', $r['submitted_at'] ?? '']
                : [$i + 1, $r['employee_name'] ?? '', $r['employee_code'] ?? '', $r['department'] ?? '', $r['submitted_at'] ?? ''];

            foreach ($survey->questions as $q) {
                $value = $byQuestion[$q->id]['answer'] ?? null;
                $row[] = is_array($value) ? implode('; ', $value) : (string) ($value ?? '');
            }

            $rows[] = $row;
        }

        return ['filename' => 'survey-'.$survey->id.'-responses.csv', 'rows' => $rows];
    }

    /* ══ Helpers ═════════════════════════════════════════════════════ */

    /** The typed answer, whichever column holds it. */
    private function answerValue(HrSurveyAnswer $a)
    {
        return match ($a->question?->question_type) {
            HrSurveyQuestion::RATING  => $a->answer_number !== null ? (float) $a->answer_number : null,
            HrSurveyQuestion::BOOLEAN => $a->answer_boolean === null ? null : ($a->answer_boolean ? 'Yes' : 'No'),
            HrSurveyQuestion::SINGLE, HrSurveyQuestion::MULTIPLE => $a->selected_options ?: [],
            default => $a->answer_text,
        };
    }

    /** How many employees the survey was addressed to — the response-rate base. */
    private function eligibleCount(HrSurvey $survey, int $tenantId): int
    {
        $q = HrEmployee::where('tenant_id', $tenantId)->where('status', 'Active');

        return match ($survey->audience) {
            'Department'  => $survey->department_id ? (clone $q)->where('department_id', $survey->department_id)->count() : 0,
            'Designation' => $survey->designation_id ? (clone $q)->where('designation_id', $survey->designation_id)->count() : 0,
            default       => $q->count(),
        };
    }

    private function find(int $id, int $tenantId): HrSurvey
    {
        $survey = HrSurvey::forTenant($tenantId)->with('questions')->find($id);
        if (! $survey) {
            throw new BusinessException('Survey not found', 404);
        }

        return $survey;
    }
}
