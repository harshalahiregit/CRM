<?php

namespace App\Support\Medical;

/**
 * The worker's overall health score, out of 10.
 *
 * Derived from the examination rather than asked for as an opinion, so two
 * doctors reading the same vitals produce the same number and a worker's
 * profile can be compared across years. A doctor who disagrees overrides it and
 * says why — the override is recorded as such (health_score_source = manual), so
 * a reviewer can always tell a measured score from a stated one.
 *
 * The scale starts at 10 and deducts for what the examination actually found.
 * Deductions are capped per group so no single measurement can sink a score on
 * its own, and an Unfit verdict caps the total, because a score of 9 on an
 * unfit worker would read as a contradiction.
 */
final class HealthScore
{
    public const MAX = 10.0;
    public const MIN = 1.0;

    /**
     * Score one examination.
     *
     * @param  array<string,mixed>  $exam  the medical record's attributes
     * @return array{score: float, factors: list<array{key:string,label:string,delta:float}>}
     */
    public static function compute(array $exam): array
    {
        $factors = [];
        $score   = self::MAX;

        foreach ([
            self::bmiFactor($exam),
            self::bloodPressureFactor($exam),
            self::oxygenFactor($exam),
            self::pulseFactor($exam),
            self::sensoryFactor($exam),
            self::historyFactor($exam),
            self::screeningFactor($exam),
        ] as $factor) {
            if ($factor !== null) {
                $factors[] = $factor;
                $score += $factor['delta'];
            }
        }

        // An unfit verdict overrides arithmetic: whatever the vitals said, the
        // examining doctor's conclusion is the ceiling.
        $cap = self::capForFitness($exam['fitness_status'] ?? null);
        if ($cap !== null && $score > $cap) {
            $factors[] = [
                'key'   => 'fitness_cap',
                'label' => 'Capped by the fitness verdict',
                'delta' => round($cap - $score, 1),
            ];
            $score = $cap;
        }

        return [
            'score'   => round(max(self::MIN, min(self::MAX, $score)), 1),
            'factors' => $factors,
        ];
    }

    /** Band a score for display — the words that go next to the number. */
    public static function band(?float $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return match (true) {
            $score >= 8.5 => 'Excellent',
            $score >= 7.0 => 'Good',
            $score >= 5.0 => 'Fair',
            default       => 'Poor',
        };
    }

    /** BMI from the recorded measurements, or null when either is missing. */
    public static function bmi(?float $heightCm, ?float $weightKg): ?float
    {
        if (! $heightCm || ! $weightKg) {
            return null;
        }
        $m = $heightCm / 100;

        return $m > 0 ? round($weightKg / ($m * $m), 1) : null;
    }

    /* ── Individual factors ─────────────────────────────────────────────── */

    private static function bmiFactor(array $exam): ?array
    {
        $bmi = self::bmi(
            isset($exam['height_cm']) ? (float) $exam['height_cm'] : null,
            isset($exam['weight_kg']) ? (float) $exam['weight_kg'] : null,
        );
        if ($bmi === null) {
            return null;
        }

        $delta = match (true) {
            $bmi < 16 || $bmi >= 35              => -2.0,
            $bmi < 18.5 || $bmi >= 30            => -1.0,
            $bmi >= 25                           => -0.5,
            default                              => 0.0,
        };

        return $delta === 0.0 ? null : ['key' => 'bmi', 'label' => 'BMI '.$bmi, 'delta' => $delta];
    }

    private static function bloodPressureFactor(array $exam): ?array
    {
        $sys = isset($exam['bp_systolic']) ? (int) $exam['bp_systolic'] : null;
        $dia = isset($exam['bp_diastolic']) ? (int) $exam['bp_diastolic'] : null;
        if (! $sys && ! $dia) {
            return null;
        }

        $delta = match (true) {
            $sys >= 160 || $dia >= 100 => -2.0,
            $sys >= 140 || $dia >= 90  => -1.0,
            $sys >= 130 || $dia >= 85  => -0.5,
            $sys > 0 && $sys < 90      => -1.0,
            default                    => 0.0,
        };

        return $delta === 0.0 ? null : ['key' => 'bp', 'label' => 'Blood pressure '.$sys.'/'.$dia, 'delta' => $delta];
    }

    private static function oxygenFactor(array $exam): ?array
    {
        $spo2 = isset($exam['spo2']) ? (int) $exam['spo2'] : null;
        if (! $spo2) {
            return null;
        }

        $delta = match (true) {
            $spo2 < 90  => -2.0,
            $spo2 < 95  => -1.0,
            default     => 0.0,
        };

        return $delta === 0.0 ? null : ['key' => 'spo2', 'label' => 'SpO₂ '.$spo2.'%', 'delta' => $delta];
    }

    private static function pulseFactor(array $exam): ?array
    {
        $pulse = isset($exam['pulse_bpm']) ? (int) $exam['pulse_bpm'] : null;
        if (! $pulse) {
            return null;
        }

        $delta = match (true) {
            $pulse < 50 || $pulse > 110 => -1.0,
            $pulse > 100                => -0.5,
            default                     => 0.0,
        };

        return $delta === 0.0 ? null : ['key' => 'pulse', 'label' => 'Pulse '.$pulse.' bpm', 'delta' => $delta];
    }

    /** Colour vision and hearing — job-relevant, so they count, but lightly. */
    private static function sensoryFactor(array $exam): ?array
    {
        $delta  = 0.0;
        $labels = [];

        if (strcasecmp((string) ($exam['colour_vision'] ?? ''), 'Deficient') === 0) {
            $delta -= 0.5;
            $labels[] = 'colour vision deficient';
        }
        if (strcasecmp((string) ($exam['hearing'] ?? ''), 'Impaired') === 0) {
            $delta -= 0.5;
            $labels[] = 'hearing impaired';
        }

        return $delta === 0.0 ? null : ['key' => 'sensory', 'label' => ucfirst(implode(', ', $labels)), 'delta' => $delta];
    }

    /** Declared chronic conditions, capped so a long history is not a death sentence. */
    private static function historyFactor(array $exam): ?array
    {
        $history = $exam['medical_history'] ?? null;
        if (is_string($history)) {
            $history = json_decode($history, true);
        }
        $conditions = is_array($history) ? ($history['conditions'] ?? []) : [];
        $count = is_array($conditions) ? count(array_filter($conditions)) : 0;
        if ($count === 0) {
            return null;
        }

        $delta = max(-2.0, -0.5 * $count);

        return ['key' => 'history', 'label' => $count.' declared condition'.($count > 1 ? 's' : ''), 'delta' => $delta];
    }

    /** The mental-health screening band already computed on the record. */
    private static function screeningFactor(array $exam): ?array
    {
        $band = $exam['screening_band'] ?? null;
        $delta = match ($band) {
            'High'     => -1.5,
            'Moderate' => -0.5,
            default    => 0.0,
        };

        return $delta === 0.0 ? null : ['key' => 'screening', 'label' => 'Screening band '.$band, 'delta' => $delta];
    }

    private static function capForFitness(?string $fitness): ?float
    {
        return match ($fitness) {
            'Unfit'                 => 3.0,
            'Expired'               => 5.0,
            'Fit_With_Restrictions' => 7.0,
            default                 => null,
        };
    }
}
