<?php

namespace App\Support\Medical;

use App\Support\Tpv\TpvMedicalFitness as Fitness;

/**
 * What an examination FOUND, named — and who else was found to have it.
 *
 * A medical record is thirty-odd numbers. "Systolic 164" is a number; "high
 * blood pressure" is a finding, and a finding is the only form in which the
 * question people actually ask can be answered: not "what was Ramesh's
 * systolic", but "which of the fourteen people we saw this morning share a
 * problem, and is it the same problem?"
 *
 * That question matters on a site. Six workers off one gang with the same
 * hearing loss is a noise-exposure problem, not six unlucky people; four with
 * the same abnormal chest film is a reason to look at where they work. Nobody
 * finds that by reading fourteen certificates one at a time, so this derives
 * the findings and groups the people by them.
 *
 * Two deliberate choices:
 *
 *  - A finding is derived, never stored. The numbers are the record; a stored
 *    label would go stale the moment the thresholds are revised, and thresholds
 *    are revised.
 *  - Declared conditions and habits each become their OWN finding — "Diabetes"
 *    is a group, not a row inside a generic "has a declared condition" bucket.
 *    Grouping only helps if the groups are specific enough to act on.
 *
 * The column names are the same across all three registers (tpv, purchase and
 * the general one), which is why this takes a plain array and serves them all.
 */
final class MedicalFindings
{
    /** Worst first — the order a doctor should read them in. */
    public const SEVERITIES = ['critical', 'serious', 'watch', 'note'];

    /**
     * Every finding in one examination.
     *
     * @param  array<string,mixed>  $exam  a medical record's attributes
     * @return list<array{key:string,label:string,severity:string,detail:?string}>
     */
    public static function of(array $exam): array
    {
        $found = [];

        foreach ([
            self::fitness($exam),
            self::bloodPressure($exam),
            self::oxygen($exam),
            self::pulse($exam),
            self::temperature($exam),
            self::bodyMass($exam),
            self::vision($exam),
            self::colourVision($exam),
            self::hearing($exam),
            self::score($exam),
        ] as $one) {
            if ($one !== null) {
                $found[] = $one;
            }
        }

        // Lists — each entry its own finding, so "Diabetes" and "Asthma" are two
        // groups rather than one indistinct "declared a condition".
        $found = [
            ...$found,
            ...self::declared($exam, 'conditions', 'condition', 'watch'),
            ...self::declared($exam, 'habits', 'habit', 'note'),
            ...self::declared($exam, 'surgeries', 'surgery', 'note'),
            ...self::abnormalInvestigations($exam),
        ];

        if ($text = self::text($exam['allergies'] ?? null)) {
            $found[] = self::make('allergy', 'Allergy declared', 'watch', $text);
        }

        if ($text = self::text($exam['current_medication'] ?? null)) {
            $found[] = self::make('medication', 'On medication', 'note', $text);
        }

        return self::sort($found);
    }

    /**
     * Group people by the findings they share.
     *
     * @param  list<array{id:mixed,name:string,context?:?string,exam:?array}>  $people
     * @return array{groups:list<array>,clear:list<array>,unexamined:list<array>,examined:int}
     */
    public static function group(array $people): array
    {
        $groups     = [];
        $clear      = [];
        $unexamined = [];
        $examined   = 0;

        foreach ($people as $person) {
            $who = [
                'id'      => $person['id'],
                'name'    => $person['name'] ?? 'Unknown',
                'context' => $person['context'] ?? null,
            ];

            if (empty($person['exam'])) {
                $unexamined[] = $who;

                continue;
            }

            $examined++;
            $findings = self::of($person['exam']);

            if (! $findings) {
                $clear[] = $who;

                continue;
            }

            foreach ($findings as $finding) {
                $key = $finding['key'];
                $groups[$key] ??= [
                    'key'      => $key,
                    'label'    => $finding['label'],
                    'severity' => $finding['severity'],
                    'people'   => [],
                ];
                $groups[$key]['people'][] = $who + ['detail' => $finding['detail']];
            }
        }

        $groups = array_values($groups);

        // Shared first, then by severity: a thing four people have is the reason
        // this screen exists, and a thing one person has is on their own record.
        usort($groups, fn ($a, $b) => [count($b['people']), self::rank($a['severity'])]
            <=> [count($a['people']), self::rank($b['severity'])]);

        return [
            'groups'     => array_map(
                fn ($g) => $g + ['count' => count($g['people']), 'shared' => count($g['people']) > 1],
                $groups,
            ),
            'clear'      => $clear,
            'unexamined' => $unexamined,
            'examined'   => $examined,
        ];
    }

    /* ── Individual findings ────────────────────────────────────────────── */

    private static function fitness(array $exam): ?array
    {
        // Compared against the shared vocabulary rather than re-spelt here, so
        // a verdict renamed in one place is not silently missed in the other.
        return match ($exam['fitness_status'] ?? null) {
            Fitness::UNFIT   => self::make('unfit', 'Declared unfit', 'critical', null),
            Fitness::EXPIRED => self::make('expired', 'Certificate expired', 'serious', null),
            Fitness::FIT_WITH_RESTRICTIONS => self::make(
                'restricted',
                'Fit with restrictions',
                'serious',
                self::text($exam['restrictions'] ?? null),
            ),
            default => null,
        };
    }

    private static function bloodPressure(array $exam): ?array
    {
        $sys = self::num($exam['bp_systolic'] ?? null);
        $dia = self::num($exam['bp_diastolic'] ?? null);

        if ($sys === null && $dia === null) {
            return null;
        }

        $reading = ($sys !== null ? (int) $sys : '?').'/'.($dia !== null ? (int) $dia : '?').' mmHg';

        return match (true) {
            $sys >= 160 || $dia >= 100   => self::make('bp_stage2', 'Blood pressure — markedly high', 'critical', $reading),
            $sys >= 140 || $dia >= 90    => self::make('bp_stage1', 'Blood pressure — high', 'serious', $reading),
            $sys !== null && $sys < 90   => self::make('bp_low', 'Blood pressure — low', 'watch', $reading),
            default                      => null,
        };
    }

    private static function oxygen(array $exam): ?array
    {
        $spo2 = self::num($exam['spo2'] ?? null);

        return match (true) {
            $spo2 === null || $spo2 <= 0 => null,
            $spo2 < 92                   => self::make('spo2_low', 'Oxygen saturation — low', 'critical', $spo2.'%'),
            $spo2 < 95                   => self::make('spo2_borderline', 'Oxygen saturation — borderline', 'serious', $spo2.'%'),
            default                      => null,
        };
    }

    private static function pulse(array $exam): ?array
    {
        $pulse = self::num($exam['pulse_bpm'] ?? null);

        return match (true) {
            $pulse === null || $pulse <= 0 => null,
            $pulse > 100                   => self::make('pulse_high', 'Pulse — fast', 'watch', $pulse.' bpm'),
            $pulse < 50                    => self::make('pulse_low', 'Pulse — slow', 'watch', $pulse.' bpm'),
            default                        => null,
        };
    }

    private static function temperature(array $exam): ?array
    {
        $temp = self::num($exam['temperature_c'] ?? null);

        return $temp !== null && $temp >= 38.0
            ? self::make('fever', 'Raised temperature', 'serious', $temp.' °C')
            : null;
    }

    private static function bodyMass(array $exam): ?array
    {
        $bmi = HealthScore::bmi(self::num($exam['height_cm'] ?? null), self::num($exam['weight_kg'] ?? null));

        return match (true) {
            $bmi === null => null,
            $bmi >= 35    => self::make('bmi_severe', 'Body mass — severely raised', 'serious', 'BMI '.$bmi),
            $bmi >= 30    => self::make('bmi_obese', 'Body mass — obese range', 'watch', 'BMI '.$bmi),
            $bmi < 18.5   => self::make('bmi_low', 'Body mass — underweight', 'watch', 'BMI '.$bmi),
            default       => null,
        };
    }

    /**
     * Acuity worse than 6/9 in either eye.
     *
     * Written as a fraction on the certificate ("6/12"), and the denominator is
     * what carries the meaning, so it is read rather than string-compared.
     */
    private static function vision(array $exam): ?array
    {
        $poor = [];

        foreach (['vision_left' => 'left', 'vision_right' => 'right'] as $column => $side) {
            $value = trim((string) ($exam[$column] ?? ''));

            if ($value !== '' && preg_match('#^\s*(\d+)\s*/\s*(\d+)#', $value, $m) && (int) $m[2] > 9) {
                $poor[] = $side.' '.$value;
            }
        }

        return $poor ? self::make('vision_reduced', 'Vision — reduced acuity', 'watch', implode(', ', $poor)) : null;
    }

    private static function colourVision(array $exam): ?array
    {
        $value = strtolower(trim((string) ($exam['colour_vision'] ?? '')));

        return ($value !== '' && $value !== 'normal')
            ? self::make('colour_vision', 'Colour vision — deficient', 'serious', null)
            : null;
    }

    private static function hearing(array $exam): ?array
    {
        $value = strtolower(trim((string) ($exam['hearing'] ?? '')));

        return ($value !== '' && $value !== 'normal')
            ? self::make('hearing', 'Hearing — impaired', 'serious', null)
            : null;
    }

    private static function score(array $exam): ?array
    {
        $score = self::num($exam['health_score'] ?? null);

        return ($score !== null && $score < 5.0)
            ? self::make('score_poor', 'Health score in the poor band', 'serious', (string) $score)
            : null;
    }

    /**
     * Declared conditions, habits and past surgery.
     *
     * Each entry gets its own key, normalised, so "Diabetes", "diabetes" and
     * " Diabetes " are one group and not three.
     *
     * @return list<array>
     */
    private static function declared(array $exam, string $bucket, string $prefix, string $severity): array
    {
        $history = self::listFrom($exam['medical_history'] ?? null);
        $entries = $history[$bucket] ?? [];

        if (! is_array($entries)) {
            return [];
        }

        $out = [];

        foreach ($entries as $entry) {
            $label = trim((string) $entry);

            // "None" is an answer, not a finding — recording it as one would put
            // every healthy person into a group called None.
            if ($label === '' || in_array(strtolower($label), self::NEGATIVES, true)) {
                continue;
            }

            $out[] = self::make($prefix.':'.self::slug($label), self::titled($label), $severity, self::LIST_NOTE[$prefix]);
        }

        return $out;
    }

    private const LIST_NOTE = [
        'condition' => 'Declared condition',
        'habit'     => 'Declared habit',
        'surgery'   => 'Past surgery',
    ];

    /** Ways of writing "nothing was found". */
    private const NEGATIVES = ['none', 'nil', 'no', 'n/a', 'na', '-', 'normal', 'nad', 'clear', 'negative', 'within normal limits', 'wnl'];

    /**
     * An investigation whose result is anything other than normal.
     *
     * The result is free text — "Normal", "NAD" and "Clear" all mean nothing was
     * found — so the negatives are listed and everything else is a finding.
     *
     * @return list<array>
     */
    private static function abnormalInvestigations(array $exam): array
    {
        $list = self::listFrom($exam['investigations'] ?? null);
        $out  = [];

        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name   = trim((string) ($item['name'] ?? ''));
            $result = trim((string) ($item['result'] ?? ''));

            if ($name === '' || $result === '' || in_array(strtolower($result), self::NEGATIVES, true)) {
                continue;
            }

            $out[] = self::make(
                'investigation:'.self::slug($name),
                self::titled($name).' — abnormal',
                'serious',
                $result,
            );
        }

        return $out;
    }

    /* ── Plumbing ───────────────────────────────────────────────────────── */

    /**
     * These columns are cast to array on every register, but a record read
     * straight from the query builder — or written before the cast was added —
     * arrives as JSON text, so both are accepted.
     */
    private static function listFrom($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    private static function make(string $key, string $label, string $severity, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'severity' => $severity, 'detail' => $detail];
    }

    private static function sort(array $findings): array
    {
        usort($findings, fn ($a, $b) => self::rank($a['severity']) <=> self::rank($b['severity']));

        return array_values($findings);
    }

    private static function rank(string $severity): int
    {
        $at = array_search($severity, self::SEVERITIES, true);

        return $at === false ? count(self::SEVERITIES) : $at;
    }

    private static function num($value): ?float
    {
        return ($value === null || $value === '' || ! is_numeric($value)) ? null : (float) $value;
    }

    private static function text($value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private static function slug(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_') ?: 'other';
    }

    private static function titled(string $value): string
    {
        return ucfirst(trim($value));
    }
}
