<?php

namespace App\Services\Hr;

use App\Contracts\AI\AIProviderInterface;
use App\Exceptions\AIException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeScore;
use App\Models\User;
use App\Services\Hr\Scoring\Employee\EmployeeScoreResult;
use App\Services\Hr\Scoring\Employee\EmployeeScoringEngine;
use Illuminate\Support\Facades\Log;

/**
 * Review comment #40 — "Positive, area of improvement, and Risk factor of this
 * employee based on profile, performance, data entry to the system etc."
 *
 * THE FACTS COME FROM THE DATA, NOT FROM A MODEL. Every item below is derived
 * from a scored dimension and carries the evidence that produced it. An AI
 * provider — the EXISTING AIProviderInterface, never a second abstraction — is
 * used only to write a short narrative over facts that are already decided.
 *
 * That ordering is the whole design. If AI is unconfigured or fails, insights
 * still generate; only the prose is missing. And because the model never invents
 * the facts, it cannot tell a manager something about an employee that the data
 * does not support — which is the "do not expose unsupported assumptions" rule.
 */
class EmployeeInsightService
{
    /** A dimension at or above this is a strength. */
    private const STRONG = 75;

    /** At or below this it needs improvement. */
    private const WEAK = 55;

    public function __construct(
        private EmployeeScoringEngine $engine,
        private AIProviderInterface $ai,
    ) {
    }

    /**
     * Generate and store insights for one employee.
     *
     * @param  bool  $withAi  ask the provider for a narrative; facts are unaffected
     */
    public function generate(HrEmployee $employee, bool $withAi = true, ?User $actor = null): HrEmployeeScore
    {
        $result = $this->engine->score($employee);

        $positives    = $this->positives($result);
        $improvements = $this->improvements($result);
        $risks        = $this->risks($employee, $result);

        [$narrative, $source, $meta] = $withAi
            ? $this->narrate($employee, $result, $positives, $improvements, $risks)
            : [null, 'rules', null];

        $score = HrEmployeeScore::updateOrCreate(
            ['tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id],
            [
                'positives'             => $positives,
                'improvements'          => $improvements,
                'risks'                 => $risks,
                'insight_narrative'     => $narrative,
                'insight_source'        => $source,
                'insight_meta'          => $meta,
                'insights_generated_at' => now(),
            ]
        );

        $employee->recordAudit('Employee insights generated', $actor, null, [
            'source'    => $source,
            'positives' => count($positives),
            'risks'     => count($risks),
        ]);

        return $score->fresh();
    }

    /* ── Facts, derived from the dimensions ───────────────────────────── */

    /** Strengths, achievements and good indicators. */
    private function positives(EmployeeScoreResult $result): array
    {
        $out = [];

        foreach ($result->dimensions as $d) {
            if (! $d->isScored() || $d->score < self::STRONG) {
                continue;
            }
            $out[] = [
                'key'      => $d->key,
                'title'    => $d->label,
                'detail'   => $d->reason,
                'score'    => $d->score,
                'evidence' => $d->evidence,
            ];
        }

        // Sort strongest first so the top of the list is the strongest claim.
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $out;
    }

    /** Skill gaps, training requirements and performance gaps. */
    private function improvements(EmployeeScoreResult $result): array
    {
        $out = [];

        foreach ($result->dimensions as $d) {
            if (! $d->isScored() || $d->score > self::WEAK) {
                continue;
            }

            $item = [
                'key'      => $d->key,
                'title'    => $d->label,
                'detail'   => $d->reason,
                'score'    => $d->score,
                'evidence' => $d->evidence,
            ];

            // Name the actual missing skills — "improve skill fit" is not an
            // action anybody can take.
            if ($d->key === 'skill_fit' && ! empty($d->evidence['missing'])) {
                $item['action'] = 'Close the skill gap: '.implode(', ', array_slice($d->evidence['missing'], 0, 5));
            }
            if ($d->key === 'training' && ! empty($d->evidence['overdue_titles'])) {
                $item['action'] = 'Complete overdue training: '.implode(', ', $d->evidence['overdue_titles']);
            }

            $out[] = $item;
        }

        usort($out, fn ($a, $b) => $a['score'] <=> $b['score']);

        return $out;
    }

    /**
     * Attrition indicators, attendance concerns and pending compliance.
     *
     * Each risk states the observation, never a prediction. "Absent on 18% of
     * working days" is a fact a manager can check; "likely to resign" is not
     * something this data supports.
     */
    private function risks(HrEmployee $employee, EmployeeScoreResult $result): array
    {
        $out = [];
        $by  = collect($result->dimensions)->keyBy('key');

        $attendance = $by->get('attendance');
        if ($attendance?->isScored() && ($attendance->evidence['absent_rate'] ?? 0) >= 10) {
            $out[] = [
                'key'      => 'attendance',
                'title'    => 'Attendance concern',
                'severity' => ($attendance->evidence['absent_rate'] >= 20) ? 'high' : 'medium',
                'detail'   => sprintf('Absent on %.1f%% of working days in the last %d months (%d of %d).',
                    $attendance->evidence['absent_rate'], EmployeeScoringEngine::WINDOW_MONTHS,
                    $attendance->evidence['absent'], $attendance->evidence['working_days']),
                'evidence' => $attendance->evidence,
            ];
        }

        $training = $by->get('training');
        if ($training?->isScored() && ($training->evidence['overdue'] ?? 0) > 0) {
            $out[] = [
                'key'      => 'training_compliance',
                'title'    => 'Training overdue',
                'severity' => ($training->evidence['overdue'] >= 3) ? 'high' : 'medium',
                'detail'   => sprintf('%d assigned training(s) past their due date.', $training->evidence['overdue']),
                'evidence' => $training->evidence,
            ];
        }

        $performance = $by->get('performance');
        if ($performance?->isScored() && $performance->score <= 45) {
            $out[] = [
                'key'      => 'performance',
                'title'    => 'Low performance rating',
                'severity' => 'high',
                'detail'   => $performance->reason,
                'evidence' => $performance->evidence,
            ];
        }

        $probation = $by->get('probation');
        if ($probation?->isScored() && ($probation->evidence['extensions'] ?? 0) > 0) {
            $out[] = [
                'key'      => 'probation',
                'title'    => 'Probation was extended',
                'severity' => 'medium',
                'detail'   => $probation->reason,
                'evidence' => $probation->evidence,
            ];
        }

        // An attrition INDICATOR, carefully worded. Long tenure with no recent
        // review is a known disengagement signal; it is reported as the two facts
        // it rests on, not as a prediction that the person will leave.
        $tenure = $by->get('tenure');
        $hasRecentReview = $performance?->isScored()
            && ! empty($performance->evidence['review_count']);

        if ($tenure?->isScored() && ($tenure->evidence['years'] ?? 0) >= 2 && ! $hasRecentReview) {
            $out[] = [
                'key'      => 'engagement',
                'title'    => 'No performance review on record',
                'severity' => 'medium',
                'detail'   => sprintf('%.1f years of service with no completed performance review — '
                    .'a review is the usual place a retention concern would surface.',
                    $tenure->evidence['years']),
                'evidence' => $tenure->evidence,
            ];
        }

        // Not a risk about the person — a gap in what we can see about them.
        if (! $result->isScored()) {
            $out[] = [
                'key'      => 'data_coverage',
                'title'    => 'Not enough data to score',
                'severity' => 'low',
                'detail'   => $result->summary,
                'evidence' => ['confidence' => $result->confidence],
            ];
        }

        return $out;
    }

    /* ── Narrative (optional) ─────────────────────────────────────────── */

    /**
     * Ask the shared provider to phrase the facts. Never to decide them.
     *
     * @return array{0: ?string, 1: string, 2: ?array}
     */
    private function narrate(HrEmployee $employee, EmployeeScoreResult $result,
        array $positives, array $improvements, array $risks): array
    {
        $facts = [
            'name'         => $employee->name,
            'designation'  => $employee->designation,
            'overall'      => $result->overallScore,
            'band'         => $result->band,
            'confidence'   => $result->confidence,
            'positives'    => array_map(fn ($p) => $p['title'].' — '.$p['detail'], $positives),
            'improvements' => array_map(fn ($i) => $i['title'].' — '.$i['detail'], $improvements),
            'risks'        => array_map(fn ($r) => $r['title'].' — '.$r['detail'], $risks),
        ];

        $system = implode(' ', [
            'You are an experienced HR business partner writing a short internal note about an employee.',
            'You are given FACTS already derived from the HR system. Summarise them in 3-4 sentences.',
            'Use ONLY the facts provided. Do not infer motivation, intent, health, family circumstances,',
            'or whether the person intends to leave. Do not invent numbers.',
            'If the facts are thin, say the picture is incomplete rather than filling the gap.',
            'Write plainly, address the reader as the manager, and never use bullet points.',
        ]);

        try {
            $raw = $this->ai->complete(
                "Facts:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ['system' => $system, 'max_tokens' => 400, 'temperature' => 0.4]
            );
        } catch (AIException $e) {
            // The facts are already decided, so a provider outage costs only the
            // prose. Degrading to 'rules' is better than failing the request.
            Log::channel('hr')->warning('Employee insight narrative unavailable', [
                'employee_id' => $employee->id, 'error' => $e->getMessage(),
            ]);

            return [null, 'rules', ['ai_error' => $e->getMessage()]];
        }

        return [
            trim($raw),
            'ai',
            [
                'provider'     => $this->ai->name(),
                'model'        => $this->ai->model(),
                'generated_at' => now()->toIso8601String(),
                // The exact facts the narrative was written from, so the note can
                // be checked against what the system actually knew.
                'facts'        => $facts,
            ],
        ];
    }
}
