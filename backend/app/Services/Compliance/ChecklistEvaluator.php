<?php

namespace App\Services\Compliance;

use App\Support\Compliance\QuestionType as Type;
use App\Support\Compliance\RiskBand;
use Illuminate\Support\Carbon;

/**
 * The engine proper: template validation, answer validation, and scoring.
 *
 * Pure logic — no models, no persistence, no actor. Everything it needs arrives
 * as arrays, which keeps the scoring rules unit-testable in isolation and lets
 * the public (unauthenticated) fill-in path reuse exactly the same validation as
 * the authenticated one. There is deliberately no client-side scoring: the rule
 * is ours, not the browser's (same stance as TpvMedicalFitness::bandForScore).
 */
class ChecklistEvaluator
{
    /** Free-text answers are evidence, not essays — bound them. */
    private const TEXT_MAX = 2000;

    /* ────────────────────────────────────────────────────────────────
     * Template definition
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Validate a template's definition. Returns a list of human-readable
     * problems; empty means the definition is safe to score against.
     *
     * This runs on WRITE so a malformed template is rejected at authoring time
     * rather than exploding mid-inspection with a half-filled form in the field.
     */
    public function validateDefinition(?array $definition): array
    {
        $errors   = [];
        $sections = $definition['sections'] ?? null;

        if (! is_array($sections) || $sections === []) {
            return ['A template needs at least one section.'];
        }

        $seenKeys = [];
        foreach ($sections as $si => $section) {
            $label = $section['title'] ?? "Section ".($si + 1);

            if (empty($section['key'])) {
                $errors[] = "{$label}: section key is required.";
            }
            $questions = $section['questions'] ?? null;
            if (! is_array($questions) || $questions === []) {
                $errors[] = "{$label}: needs at least one question.";
                continue;
            }

            foreach ($questions as $qi => $q) {
                $qLabel = $q['label'] ?? "question ".($qi + 1);
                $key    = $q['key'] ?? null;

                if (empty($key)) {
                    $errors[] = "{$label} / {$qLabel}: question key is required.";
                    continue;
                }
                // Keys index the responses map, so a duplicate would let one
                // answer silently overwrite another.
                if (in_array($key, $seenKeys, true)) {
                    $errors[] = "Duplicate question key '{$key}' — keys must be unique across the whole template.";
                }
                $seenKeys[] = $key;

                if (empty($q['label'])) {
                    $errors[] = "Question '{$key}': label is required.";
                }
                if (! Type::isValid($q['type'] ?? null)) {
                    $errors[] = "Question '{$key}': unknown type '".($q['type'] ?? '')."'.";
                    continue;
                }

                $errors = array_merge($errors, $this->validateQuestionShape($key, $q));
            }
        }

        return $errors;
    }

    /** Per-type authoring rules. */
    private function validateQuestionShape(string $key, array $q): array
    {
        $errors = [];

        switch ($q['type']) {
            case Type::BOOLEAN:
                $weight = $q['weight'] ?? 1;
                if (! is_numeric($weight) || $weight < 0) {
                    $errors[] = "Question '{$key}': weight must be a number of 0 or more.";
                }
                if (array_key_exists('risk_when', $q) && ! is_bool($q['risk_when'])) {
                    $errors[] = "Question '{$key}': risk_when must be true or false.";
                }
                break;

            case Type::CHOICE:
                $options = $q['options'] ?? null;
                if (! is_array($options) || $options === []) {
                    $errors[] = "Question '{$key}': a choice question needs options.";
                    break;
                }
                $values = [];
                foreach ($options as $oi => $o) {
                    if (! isset($o['value']) || $o['value'] === '') {
                        $errors[] = "Question '{$key}': option ".($oi + 1)." needs a value.";
                        continue;
                    }
                    if (in_array($o['value'], $values, true)) {
                        $errors[] = "Question '{$key}': duplicate option value '{$o['value']}'.";
                    }
                    $values[] = $o['value'];

                    if (isset($o['risk']) && (! is_numeric($o['risk']) || $o['risk'] < 0)) {
                        $errors[] = "Question '{$key}': option '{$o['value']}' has an invalid risk.";
                    }
                }
                break;

            case Type::NUMBER:
                $per = $q['risk_per_unit'] ?? 0;
                if (! is_numeric($per) || $per < 0) {
                    $errors[] = "Question '{$key}': risk_per_unit must be a number of 0 or more.";
                    break;
                }
                // An uncapped, unbounded numeric would make the template's maximum
                // — and therefore every band — undefined.
                if ($per > 0 && ! isset($q['risk_cap']) && ! isset($q['max'])) {
                    $errors[] = "Question '{$key}': a scored number needs a risk_cap or a max, otherwise the template has no maximum risk.";
                }
                if (isset($q['min'], $q['max']) && $q['min'] > $q['max']) {
                    $errors[] = "Question '{$key}': min is greater than max.";
                }
                break;
        }

        return $errors;
    }

    /** Flatten the definition to [key => question]. */
    public function questions(?array $definition): array
    {
        $out = [];
        foreach ($definition['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $q) {
                if (! empty($q['key'])) {
                    $out[$q['key']] = $q;
                }
            }
        }

        return $out;
    }

    /* ────────────────────────────────────────────────────────────────
     * Answers
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Validate answers against a definition. Returns [questionKey => message].
     *
     * $partial allows saving a half-finished form (the assignee walking a site
     * over an hour) without tripping every required rule; submit passes false.
     */
    public function validateResponses(?array $definition, array $responses, bool $partial = false): array
    {
        $errors = [];

        foreach ($this->questions($definition) as $key => $q) {
            $answer = $this->normaliseAnswer($responses[$key] ?? null);
            $value  = $answer['value'];
            $isNa   = $answer['na'];

            if ($isNa) {
                if (empty($q['allow_na'])) {
                    $errors[$key] = 'This question cannot be marked not-applicable.';
                }
                continue;
            }

            $missing = $value === null || $value === '';

            if ($missing) {
                if (! $partial && ! empty($q['required'])) {
                    $errors[$key] = 'This question is required.';
                }
                continue;
            }

            if ($message = $this->validateAnswerValue($q, $value)) {
                $errors[$key] = $message;
                continue;
            }

            // A risky answer on a question that demands justification must carry
            // one — "no harness" with no note is not an inspection record.
            if (! $partial && ! empty($q['remark_when_risky'])
                && $this->riskFor($q, $value)['risk'] > 0
                && trim((string) ($answer['remark'] ?? '')) === '') {
                $errors[$key] = 'A remark is required to explain this answer.';
            }
        }

        // An answer to a question that no longer exists is a sign the template
        // changed underneath the form — surface it rather than scoring around it.
        $unknown = array_diff(array_keys($responses), array_keys($this->questions($definition)));
        foreach ($unknown as $key) {
            $errors[$key] = 'This question is not part of the template.';
        }

        return $errors;
    }

    private function validateAnswerValue(array $q, $value): ?string
    {
        switch ($q['type']) {
            case Type::BOOLEAN:
                if (! is_bool($value)) {
                    return 'Answer must be yes or no.';
                }
                break;

            case Type::CHOICE:
                $values = array_column($q['options'] ?? [], 'value');
                if (! in_array($value, $values, true)) {
                    return 'Choose one of the listed options.';
                }
                break;

            case Type::NUMBER:
                if (! is_numeric($value)) {
                    return 'Answer must be a number.';
                }
                if (isset($q['min']) && $value < $q['min']) {
                    return "Answer must be {$q['min']} or more.";
                }
                if (isset($q['max']) && $value > $q['max']) {
                    return "Answer must be {$q['max']} or less.";
                }
                break;

            case Type::TEXT:
                if (! is_string($value)) {
                    return 'Answer must be text.';
                }
                if (mb_strlen($value) > self::TEXT_MAX) {
                    return 'Answer is too long (max '.self::TEXT_MAX.' characters).';
                }
                break;

            case Type::DATE:
                try {
                    Carbon::parse((string) $value);
                } catch (\Throwable) {
                    return 'Answer must be a valid date.';
                }
                break;
        }

        return null;
    }

    /** Accept either {"value":x,"remark":y,"na":bool} or a bare scalar. */
    private function normaliseAnswer($raw): array
    {
        if (is_array($raw)) {
            return [
                'value'  => $raw['value'] ?? null,
                'remark' => $raw['remark'] ?? null,
                'na'     => ! empty($raw['na']),
            ];
        }

        return ['value' => $raw, 'remark' => null, 'na' => false];
    }

    /* ────────────────────────────────────────────────────────────────
     * Scoring
     * ──────────────────────────────────────────────────────────────── */

    /** Risk earned by one answer, and whether it trips a critical control. */
    private function riskFor(array $q, $value): array
    {
        switch ($q['type']) {
            case Type::BOOLEAN:
                $riskWhen = $q['risk_when'] ?? false;
                $hit      = $value === $riskWhen;

                return [
                    'risk'     => $hit ? (float) ($q['weight'] ?? 1) : 0.0,
                    'critical' => $hit && ! empty($q['critical']),
                ];

            case Type::CHOICE:
                foreach ($q['options'] ?? [] as $o) {
                    if ($o['value'] === $value) {
                        $risk = (float) ($o['risk'] ?? 0);

                        return ['risk' => $risk, 'critical' => $risk > 0 && ! empty($o['critical'])];
                    }
                }

                return ['risk' => 0.0, 'critical' => false];

            case Type::NUMBER:
                $per  = (float) ($q['risk_per_unit'] ?? 0);
                $risk = (float) $value * $per;
                if (isset($q['risk_cap'])) {
                    $risk = min($risk, (float) $q['risk_cap']);
                }

                return ['risk' => max(0.0, $risk), 'critical' => false];
        }

        return ['risk' => 0.0, 'critical' => false];
    }

    /** The worst this question could score — the denominator's contribution. */
    private function maxRiskFor(array $q): float
    {
        switch ($q['type']) {
            case Type::BOOLEAN:
                return (float) ($q['weight'] ?? 1);

            case Type::CHOICE:
                $risks = array_map(fn ($o) => (float) ($o['risk'] ?? 0), $q['options'] ?? []);

                return $risks ? max($risks) : 0.0;

            case Type::NUMBER:
                $per = (float) ($q['risk_per_unit'] ?? 0);
                if ($per <= 0) {
                    return 0.0;
                }
                if (isset($q['risk_cap'])) {
                    return (float) $q['risk_cap'];
                }

                return isset($q['max']) ? (float) $q['max'] * $per : 0.0;
        }

        return 0.0;
    }

    /**
     * Score a set of answers.
     *
     * The denominator is the template's own maximum risk, so two instances of
     * the same template are directly comparable. Questions answered "not
     * applicable" drop out of BOTH the score and the maximum — a scaffolding
     * question on an electrical job must not quietly inflate the risk, nor
     * deflate the denominator and drag every band down with it.
     */
    public function evaluate(?array $definition, array $responses, ?array $thresholds = null): array
    {
        $score = 0.0;
        $max   = 0.0;
        $criticalFailures = [];

        foreach ($this->questions($definition) as $key => $q) {
            if (! Type::isScorable($q['type'] ?? null)) {
                continue;
            }

            $answer = $this->normaliseAnswer($responses[$key] ?? null);

            if ($answer['na'] && ! empty($q['allow_na'])) {
                continue;
            }

            $max += $this->maxRiskFor($q);

            if ($answer['value'] === null || $answer['value'] === '') {
                continue;
            }

            $r = $this->riskFor($q, $answer['value']);
            $score += $r['risk'];
            if ($r['critical']) {
                $criticalFailures[] = $key;
            }
        }

        // A template of only text questions has no risk surface; calling that
        // 0% (Low) would imply it was assessed and passed.
        $percent = $max > 0 ? round(($score / $max) * 100, 2) : 0.0;
        $band    = $max > 0
            ? RiskBand::forPercent($percent, $thresholds, $criticalFailures !== [])
            : null;

        return [
            'score'             => (int) round($score),
            'max_score'         => (int) round($max),
            'risk_percent'      => $percent,
            'risk_band'         => $band,
            'critical_failures' => $criticalFailures,
        ];
    }
}
