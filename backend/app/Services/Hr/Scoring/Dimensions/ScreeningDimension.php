<?php

namespace App\Services\Hr\Scoring\Dimensions;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;

/**
 * Screening-question answers.
 *
 * The old version returned 100 whenever a job had no screening questions — and 25
 * of 26 postings have none, so a fabricated 100 was carrying 20% of every
 * career-portal score. No questions now means nothing to measure.
 *
 * Where an expected answer IS configured, correctness is scored. Where it is not,
 * only completeness can honestly be measured, and the reason says so.
 */
class ScreeningDimension
{
    public const KEY = 'screening';

    public function score(HrCandidate $candidate, ?HrJobPosting $job): DimensionResult
    {
        $label = 'Screening';

        $questions = $this->questions($job);
        if ($questions === []) {
            return DimensionResult::unavailable(self::KEY, $label,
                'This role has no screening questions configured.');
        }

        $answers = $this->answers($candidate);
        if ($answers === []) {
            return DimensionResult::unavailable(self::KEY, $label,
                'The candidate has not submitted screening answers.',
                ['question_count' => count($questions)]);
        }

        $byId = [];
        foreach ($answers as $a) {
            if (is_array($a) && isset($a['question_id'])) {
                $byId[(string) $a['question_id']] = $a['value'] ?? null;
            }
        }

        $graded = 0; $correct = 0; $answered = 0;
        foreach ($questions as $q) {
            $qid = (string) ($q['id'] ?? '');
            $val = $byId[$qid] ?? null;
            $has = $val !== null && $val !== '' && $val !== [];
            if ($has) {
                $answered++;
            }

            // An expected answer makes the question objectively gradeable.
            $expected = $q['expected_answer'] ?? $q['correct_answer'] ?? null;
            if ($expected !== null && $expected !== '') {
                $graded++;
                if ($has && $this->same($val, $expected)) {
                    $correct++;
                }
            }
        }

        if ($graded > 0) {
            return DimensionResult::scored(self::KEY, $label, ($correct / $graded) * 100, sprintf(
                '%d of %d gradeable screening question(s) answered correctly.', $correct, $graded
            ), ['graded' => $graded, 'correct' => $correct, 'answered' => $answered, 'total' => count($questions)]);
        }

        // No answer key exists. Completeness is the only honest measurement, and the
        // reason states that it is participation, not correctness.
        return DimensionResult::scored(self::KEY, $label, ($answered / count($questions)) * 100, sprintf(
            '%d of %d screening question(s) answered. No answer key is configured, so this measures completeness, not correctness.',
            $answered, count($questions)
        ), ['answered' => $answered, 'total' => count($questions), 'graded' => 0]);
    }

    private function questions(?HrJobPosting $job): array
    {
        $q = $job?->screening_questions;
        if (is_string($q)) {
            $q = json_decode($q, true);
        }

        return array_values(array_filter((array) ($q ?: []), 'is_array'));
    }

    private function answers(HrCandidate $candidate): array
    {
        $a = $candidate->screening_answers;
        if (is_string($a)) {
            $a = json_decode($a, true);
        }

        return array_values((array) ($a ?: []));
    }

    private function same($given, $expected): bool
    {
        $norm = fn ($v) => strtolower(trim(is_array($v) ? implode(',', $v) : (string) $v));

        return $norm($given) === $norm($expected);
    }
}
