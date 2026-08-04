<?php

namespace App\Services\Hr\Scoring\Employee;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeScore;
use App\Models\Hr\HrEmployeeScoreHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * #39 — persistence for the employee score.
 *
 * The split that matters: history is written FIRST and never updated, then the
 * current row is overwritten. "Allow recalculation / do not overwrite historical
 * scores" is only true if nothing in the codebase ever updates or deletes a
 * history row — and nothing does.
 *
 * Kept apart from the engine so `score()` stays a pure dry run.
 */
class EmployeeScoreRecorder
{
    public function record(
        HrEmployee $employee,
        EmployeeScoreResult $result,
        string $trigger = 'manual',
        ?User $actor = null,
    ): HrEmployeeScore {
        return DB::transaction(function () use ($employee, $result, $trigger, $actor) {
            $current = HrEmployeeScore::where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)->first();

            // History first — capture what the score WAS before it is replaced.
            HrEmployeeScoreHistory::create([
                'tenant_id'      => $employee->tenant_id,
                'employee_id'    => $employee->id,
                'overall_score'  => $result->overallScore,
                'confidence'     => $result->confidence,
                'band'           => $result->band,
                'dimensions'     => array_map(fn ($d) => $d->toArray(), $result->dimensions),
                'previous_score' => $current?->overall_score,
                'trigger'        => $trigger,
                'scored_by'      => $actor?->id,
            ]);

            // Insight columns are deliberately NOT touched here. Scoring and
            // insight generation are separate actions; a recalculation must not
            // silently blank insights the user is still reading.
            $score = HrEmployeeScore::updateOrCreate(
                ['tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id],
                [
                    'overall_score'     => $result->overallScore,
                    'provisional_score' => $result->provisionalScore,
                    'confidence'        => $result->confidence,
                    'band'              => $result->band,
                    'summary'           => $result->summary,
                    'dimensions'        => array_map(fn ($d) => $d->toArray(), $result->dimensions),
                    'applied_weights'   => $result->appliedWeights,
                    'scored_at'         => now(),
                    'scored_by'         => $actor?->id,
                ]
            );

            $employee->recordAudit('Employee score calculated', $actor, null, [
                'overall_score' => $result->overallScore,
                'confidence'    => $result->confidence,
                'trigger'       => $trigger,
            ]);

            return $score->fresh();
        });
    }

    /** The score trend, newest first. Read-only — history is never rewritten. */
    public function history(HrEmployee $employee, int $limit = 20): array
    {
        return HrEmployeeScoreHistory::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->latest('id')->limit($limit)->get()
            ->map(fn ($h) => [
                'id'             => $h->id,
                'overall_score'  => $h->overall_score,
                'previous_score' => $h->previous_score,
                'delta'          => ($h->overall_score !== null && $h->previous_score !== null)
                    ? $h->overall_score - $h->previous_score : null,
                'confidence'     => $h->confidence,
                'band'           => $h->band,
                'trigger'        => $h->trigger,
                'scored_at'      => $h->created_at?->toIso8601String(),
            ])->all();
    }
}
