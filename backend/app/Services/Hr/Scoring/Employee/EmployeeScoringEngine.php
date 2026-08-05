<?php

namespace App\Services\Hr\Scoring\Employee;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeProbation;
use App\Models\Hr\HrEmployeeTraining;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrPerformanceReview;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;
use App\Services\Hr\Scoring\Employee\Dimensions\AttendanceDimension;
use App\Services\Hr\Scoring\Employee\Dimensions\ContributionDimension;
use App\Services\Hr\Scoring\Employee\Dimensions\LeaveBehaviourDimension;
use App\Services\Hr\Scoring\Employee\Dimensions\PerformanceDimension;
use App\Services\Hr\Scoring\Employee\Dimensions\ProbationDimension;
use App\Services\Hr\Scoring\Employee\Dimensions\SkillFitDimension;
use App\Services\Hr\Scoring\Employee\Dimensions\TenureDimension;
use App\Services\Hr\Scoring\Employee\Dimensions\TrainingDimension;
use App\Support\Hr\SkillMatcher;
use Illuminate\Support\Facades\DB;

/**
 * Review comment #39 — "Employee overall score?".
 *
 * A SEPARATE engine from CandidateScoringEngine, not a reuse of it. A candidate
 * is scored on FIT for a job they do not have; an employee is scored on what
 * they have actually done in a job they hold. The two share no dimension, no
 * weight and no vocabulary — only the DimensionResult value object, whose
 * "unavailable ≠ zero" rule both need.
 *
 * The rules that make the number honest, carried over deliberately:
 *
 *  - A dimension with nothing to measure returns `unavailable()` and is dropped
 *    from the weighted average. It is never scored zero.
 *  - Confidence is the share of total weight that could actually be measured.
 *  - Below the confidence floor NO score is published. Renormalising over a
 *    sliver of weight produces a technically-correct 100 from one dimension,
 *    which is fabrication dressed as data.
 *
 * `score()` performs no writes, so it is safe as a dry run.
 */
class EmployeeScoringEngine
{
    /** Weights sum to 100. Performance dominates; tenure is deliberately small. */
    public const WEIGHTS = [
        PerformanceDimension::KEY    => 30,
        ContributionDimension::KEY   => 15,
        AttendanceDimension::KEY     => 15,
        TrainingDimension::KEY       => 12,
        SkillFitDimension::KEY       => 12,
        LeaveBehaviourDimension::KEY => 8,
        ProbationDimension::KEY      => 5,
        TenureDimension::KEY         => 3,
    ];

    /** Below this share of measurable weight, no score is published. */
    public const MIN_CONFIDENCE = 35;

    /** How far back attendance and leave are read. */
    public const WINDOW_MONTHS = 12;

    /** @var \App\Services\Hr\Scoring\Employee\Dimensions\EmployeeDimension[] */
    private array $dimensions;

    public function __construct()
    {
        $this->dimensions = [
            new PerformanceDimension(),
            new ContributionDimension(),
            new AttendanceDimension(),
            new TrainingDimension(),
            new SkillFitDimension(),
            new LeaveBehaviourDimension(),
            new ProbationDimension(),
            new TenureDimension(),
        ];
    }

    public function score(HrEmployee $employee): EmployeeScoreResult
    {
        $ctx = $this->context($employee);

        /** @var DimensionResult[] $results */
        $results = array_map(fn ($d) => $d->score($employee, $ctx), $this->dimensions);

        $totalWeight     = array_sum(self::WEIGHTS);
        $availableWeight = 0;
        $weighted        = 0.0;
        $applied         = [];

        foreach ($results as $r) {
            $weight = self::WEIGHTS[$r->key] ?? 0;
            if (! $r->isScored()) {
                continue;
            }
            $availableWeight += $weight;
            $weighted        += $r->score * $weight;
            $applied[$r->key] = $weight;
        }

        $provisional = $availableWeight > 0 ? (int) round($weighted / $availableWeight) : null;
        $confidence  = $totalWeight > 0 ? (int) round(($availableWeight / $totalWeight) * 100) : 0;

        // Same suppression rule as the candidate engine, for the same reason: a
        // score built on 3% of the weight is not a score.
        $overall = ($provisional !== null && $confidence >= self::MIN_CONFIDENCE) ? $provisional : null;

        return new EmployeeScoreResult(
            overallScore: $overall,
            provisionalScore: $provisional,
            confidence: $confidence,
            band: $this->band($overall),
            dimensions: $results,
            appliedWeights: $applied,
            summary: $this->summary($employee, $overall, $confidence, $results),
        );
    }

    /**
     * Everything the dimensions read, loaded ONCE.
     *
     * Dimensions never query — otherwise scoring the whole company would be
     * employees × dimensions round trips.
     */
    private function context(HrEmployee $employee): array
    {
        $since = now()->subMonths(self::WINDOW_MONTHS)->startOfDay();

        return [
            'reviews' => HrPerformanceReview::where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                // A draft rating is a manager's work in progress, not a company judgement.
                ->whereIn('status', ['Submitted', 'Approved', 'Completed'])
                ->get(),

            'trainings' => HrEmployeeTraining::where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->with('program:id,title')
                ->get(),

            'attendance' => HrAttendance::where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->where('date', '>=', $since)
                ->get(['id', 'status', 'date']),

            'leave' => HrLeaveApplication::where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->where('created_at', '>=', $since)
                ->get(['id', 'status', 'days']),

            'probation' => HrEmployeeProbation::where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->latest('id')->first(),

            'expected_skills' => $this->expectedSkills($employee),

            'tasks' => $this->tasks($employee),
        ];
    }

    /**
     * The skills the employee's position expects — #43's masters, unioned.
     *
     * Department, designation, grade and role each carry a skill list; a person
     * is expected to hold the union of the ones that apply to them.
     */
    private function expectedSkills(HrEmployee $employee): array
    {
        $lists = [];

        foreach ([
            ['hr_departments', $employee->department_id],
            ['hr_designations', $employee->designation_id],
            ['hr_grades', $employee->grade_id],
            ['hr_job_roles', $employee->job_role_id],
        ] as [$table, $id]) {
            if (! $id || ! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }
            $raw = DB::table($table)->where('id', $id)->value('skills');
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (is_array($decoded)) {
                $lists[] = $decoded;
            }
        }

        return SkillMatcher::clean(array_merge(...($lists ?: [[]])));
    }

    /**
     * Tasks assigned to this employee's user account.
     *
     * Guarded on the link and the table: the employee↔user link is optional, and
     * the Tasks module may not be installed in every deployment.
     */
    private function tasks(HrEmployee $employee): array
    {
        if (empty($employee->user_id)
            || ! \Illuminate\Support\Facades\Schema::hasTable('tasks')
            || ! \Illuminate\Support\Facades\Schema::hasTable('task_assignees')) {
            return [];
        }

        // Assignment is many-to-many via task_assignees — `tasks` itself carries
        // no assignee column, so a `where('assigned_to', …)` would silently
        // return nothing and read as "no tasks assigned".
        return DB::table('tasks')
            ->join('task_assignees', 'task_assignees.task_id', '=', 'tasks.id')
            ->where('task_assignees.user_id', $employee->user_id)
            ->whereNull('tasks.deleted_at')
            ->get(['tasks.id', 'tasks.date_finished', 'tasks.due_date'])
            ->all();
    }

    /** A word for the number, so the UI never has to invent its own bands. */
    private function band(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return match (true) {
            $score >= 85 => 'Excellent',
            $score >= 70 => 'Strong',
            $score >= 55 => 'Steady',
            $score >= 40 => 'Needs Support',
            default      => 'At Risk',
        };
    }

    /** @param DimensionResult[] $results */
    private function summary(HrEmployee $employee, ?int $overall, int $confidence, array $results): string
    {
        $measured = count(array_filter($results, fn ($r) => $r->isScored()));
        $total    = count($results);

        if ($overall === null) {
            return sprintf(
                '%s has no overall score yet: only %d of %d areas could be measured (%d%% confidence, %d%% needed). '
                .'Record performance reviews, attendance or training to build one.',
                $employee->name, $measured, $total, $confidence, self::MIN_CONFIDENCE
            );
        }

        return sprintf('%s scores %d%% (%s), measured across %d of %d areas at %d%% confidence.',
            $employee->name, $overall, $this->band($overall), $measured, $total, $confidence);
    }
}
