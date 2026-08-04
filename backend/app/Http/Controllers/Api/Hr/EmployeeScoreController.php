<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeScore;
use App\Services\Hr\EmployeeInsightService;
use App\Services\Hr\Scoring\Employee\EmployeeScoreRecorder;
use App\Services\Hr\Scoring\Employee\EmployeeScoringEngine;
use Illuminate\Http\Request;

/**
 * #39 (overall score) and #40 (positive / improvement / risk).
 *
 * HR-gated throughout. A score is a judgement about a person and a risk factor
 * is a sensitive one — neither belongs on an endpoint a colleague can read.
 */
class EmployeeScoreController extends Controller
{
    public function __construct(
        private EmployeeScoringEngine $engine,
        private EmployeeScoreRecorder $recorder,
        private EmployeeInsightService $insights,
    ) {
    }

    /** The stored score + insights. Never computes — a read must not write. */
    public function show(Request $request, HrEmployee $employee)
    {
        $this->authorise($request, $employee);

        $score = HrEmployeeScore::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)->first();

        return response()->json([
            'employee_id' => $employee->id,
            'scored'      => (bool) $score?->scored_at,
            'score'       => $score ? $this->present($score) : null,
            'history'     => $score ? $this->recorder->history($employee) : [],
        ]);
    }

    /**
     * Recalculate. Appends to history; never rewrites it.
     *
     * POST rather than GET because it writes — and because "recalculate" is an
     * action a user takes, not a page they load.
     */
    public function recalculate(Request $request, HrEmployee $employee)
    {
        $this->authorise($request, $employee);

        $result = $this->engine->score($employee);
        $score  = $this->recorder->record($employee, $result, $request->input('trigger', 'manual'), $request->user());

        return response()->json([
            'employee_id' => $employee->id,
            'score'       => $this->present($score),
            'history'     => $this->recorder->history($employee),
        ]);
    }

    /** A dry run — computes and returns without persisting anything. */
    public function preview(Request $request, HrEmployee $employee)
    {
        $this->authorise($request, $employee);

        return response()->json($this->engine->score($employee)->toArray());
    }

    /** #40 — (re)generate the three insight groups. */
    public function insights(Request $request, HrEmployee $employee)
    {
        $this->authorise($request, $employee);

        $score = $this->insights->generate(
            $employee,
            // Narrative is opt-out, so a tenant with no AI key still gets facts.
            withAi: $request->boolean('with_ai', true),
            actor: $request->user(),
        );

        return response()->json($this->present($score));
    }

    private function present(HrEmployeeScore $score): array
    {
        return [
            'overall_score'     => $score->overall_score,
            'provisional_score' => $score->provisional_score,
            'confidence'        => $score->confidence,
            'band'              => $score->band,
            'summary'           => $score->summary,
            'dimensions'        => $score->dimensions ?: [],
            'applied_weights'   => $score->applied_weights ?: [],
            'positives'         => $score->positives ?: [],
            'improvements'      => $score->improvements ?: [],
            'risks'             => $score->risks ?: [],
            'insight_narrative' => $score->insight_narrative,
            'insight_source'    => $score->insight_source,
            'insight_meta'      => $score->insight_meta,
            'insights_generated_at' => optional($score->insights_generated_at)->toIso8601String(),
            'scored_at'         => optional($score->scored_at)->toIso8601String(),
        ];
    }

    private function authorise(Request $request, HrEmployee $employee): void
    {
        // Route-model binding must not leak another tenant's employee.
        if ((int) $employee->tenant_id !== (int) $request->user()->tenant_id) {
            throw new BusinessException('Employee not found', 404);
        }

        abort_unless($request->user()->canManageHrQueue(), 403,
            'You are not authorised to view employee scores');
    }
}
