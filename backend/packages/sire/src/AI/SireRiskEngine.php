<?php

namespace Sire\AI;

/**
 * SIRE — the three risk engines.
 *
 * Direct port of tests/reference/riskEngines.mjs; both run
 * fixtures/risk-engine-cases.json.
 *
 * ONE BANDING SCALE FOR ALL THREE. A "HIGH" on a release and a "HIGH" on an issue
 * must mean a comparable weight of evidence, otherwise the word stops carrying
 * information and people learn to ignore whichever one cries wolf.
 *
 * EVERY FACTOR CARRIES ITS OWN SENTENCE. The brief asks for risk "with reasons",
 * and a reason has to be something a person can disagree with: "3 of the last 14
 * issues in Sales were regressions" is arguable; "risk score 7" is not.
 *
 * Pure arithmetic — no database, no models. The services above gather the numbers;
 * this decides what they mean, which is why it can be fixture-tested exactly.
 */
class SireRiskEngine
{
    public const LOW    = 'low';
    public const MEDIUM = 'medium';
    public const HIGH   = 'high';

    public const THRESHOLD_HIGH   = 6;
    public const THRESHOLD_MEDIUM = 3;

    public function band(int $score): string
    {
        return match (true) {
            $score >= self::THRESHOLD_HIGH   => self::HIGH,
            $score >= self::THRESHOLD_MEDIUM => self::MEDIUM,
            default                          => self::LOW,
        };
    }

    /** How likely is fixing this issue to break something that worked? */
    public function regressionRisk(array $i): array
    {
        $rate = (float) ($i['module_regression_rate'] ?? 0);
        $count = (int) ($i['module_issue_count'] ?? 0);
        $reopens = (int) ($i['reopen_count'] ?? 0);

        return $this->assemble([
            // Module history is the strongest single signal — but only once there
            // is enough of it. Two issues in a module is not a track record.
            $count >= 5
                ? $this->factor(
                    'module_regression_history',
                    $rate >= 0.2 ? 3 : ($rate >= 0.1 ? 2 : ($rate > 0 ? 1 : 0)),
                    sprintf('%d%% of recent %s issues were regressions (%d looked at).',
                        (int) round($rate * 100), $i['module'] ?? 'module', $count),
                )
                : null,

            $this->factor('already_a_regression', ! empty($i['is_regression']) ? 2 : 0,
                'This issue is itself a regression — the area has broken under change before.'),

            $this->factor('reopened', $reopens >= 2 ? 2 : ($reopens === 1 ? 1 : 0),
                "Reopened {$reopens} time(s): earlier fixes here did not hold."),

            $this->factor('severity',
                ($i['severity_code'] ?? null) === 'critical' ? 2 : (($i['severity_code'] ?? null) === 'major' ? 1 : 0),
                sprintf('Severity is %s — a regression here would be felt immediately.', $i['severity_code'] ?? ''),
            ),

            $this->factor('root_cause_class',
                in_array($i['root_cause_category'] ?? null, ['code', 'design'], true) ? 1 : 0,
                sprintf('Root cause was %s: changes of that kind carry more blast radius than data or configuration fixes.',
                    $i['root_cause_category'] ?? ''),
            ),

            /*
             * The test signal is the one thing the team can act on before shipping,
             * which is why it is weighted like history rather than like a hint.
             *
             * Absent means NOT MEASURED and scores nothing — the same rule as an
             * unknown QA pass rate. Firing on absent data would let an engine that
             * was handed nothing announce a finding.
             */
            $this->factor('no_tests', ($i['active_test_count'] ?? null) === 0 ? 2 : 0,
                'No test cases are recorded against this issue — nothing will catch it coming back.'),

            $this->factor('regression_test_outstanding',
                (! empty($i['regression_test_required']) && empty($i['regression_test_done'])) ? 1 : 0,
                'A regression test is required for this issue and has not been run.'),

            $this->factor('prior_release_regressions', ! empty($i['prior_release_caused_regressions']) ? 1 : 0,
                'The previous release touching this area caused regressions of its own.'),
        ]);
    }

    /**
     * How likely is this defect to come back?
     *
     * DELIBERATELY DIFFERENT INPUTS from SireRecurrenceService::assessRisk(), which
     * scores a recurrence GROUP from its cadence and fix status. This scores an
     * ISSUE from its own history — reopens, repeated fixes, workaround language.
     *
     * The two are shown side by side and never merged. A deterministic formula you
     * can explain should not be overwritten by a second opinion, and a second
     * opinion that only ever agreed would be worth nothing.
     */
    public function recurrenceRisk(array $i): array
    {
        $occurrences = (int) ($i['occurrence_count'] ?? 0);
        $reopens = (int) ($i['reopen_count'] ?? 0);
        $sameCause = (int) ($i['same_root_cause_count'] ?? 0);
        $fixes = (int) ($i['distinct_fix_versions'] ?? 0);
        $workarounds = $i['workaround_signals'] ?? [];

        return $this->assemble([
            $this->factor('occurrences',
                $occurrences >= 5 ? 3 : ($occurrences >= 3 ? 2 : ($occurrences === 2 ? 1 : 0)),
                "This defect has been recorded {$occurrences} times."),

            $this->factor('reopened', $reopens >= 2 ? 2 : ($reopens === 1 ? 1 : 0),
                "Reopened {$reopens} time(s) after being closed."),

            $this->factor('same_root_cause_repeats',
                $sameCause >= 3 ? 2 : ($sameCause >= 1 ? 1 : 0),
                "{$sameCause} other issue(s) in this area shared the same root cause category."),

            // Several fixes shipped for one defect is the clearest evidence that
            // none of them addressed the cause.
            $this->factor('repeated_fixes', $fixes >= 3 ? 2 : ($fixes === 2 ? 1 : 0),
                "{$fixes} separate releases have shipped a fix for this — none of them held."),

            $this->factor('workaround_language', $workarounds !== [] ? 2 : 0,
                sprintf('The fix is described in terms of a workaround (%s), which tends to defer a defect rather than end it.',
                    implode(', ', array_slice($workarounds, 0, 3)))),

            $this->factor('no_permanent_fix', ($i['permanent_fix_status'] ?? null) === 'none' ? 1 : 0,
                'No permanent fix is planned for the underlying pattern.'),

            // The only negative factor in any engine. A verified permanent fix is
            // real evidence AGAINST recurrence and should pull a score down.
            $this->factor('permanent_fix_verified', ($i['permanent_fix_status'] ?? null) === 'verified' ? -2 : 0,
                'A permanent fix has been shipped and verified, which counts against recurrence.'),
        ]);
    }

    /**
     * How risky is shipping this release?
     *
     * ADVISORY ONLY, AND NEVER A GATE. Gates are deterministic, explainable and
     * BLOCK; this is a judgement and INFORMS. A release must never be stopped by
     * something whose reasoning nobody can reconstruct — see D28.
     */
    public function releaseRisk(array $r): array
    {
        $passRate = $r['qa_pass_rate'] ?? null;
        $regressions = (int) ($r['regression_count'] ?? 0);
        $total = (int) ($r['total_issues'] ?? 0);
        $history = (int) ($r['prior_releases_caused_regressions'] ?? 0);

        return $this->assemble([
            $this->factor('open_critical', (int) ($r['open_critical'] ?? 0) > 0 ? 3 : 0,
                sprintf('%d critical issue(s) are still open in this release.', $r['open_critical'] ?? 0)),

            $this->factor('qa_failed', (int) ($r['qa_failed'] ?? 0) > 0 ? 2 : 0,
                sprintf('%d issue(s) are sitting in QA Failed.', $r['qa_failed'] ?? 0)),

            $this->factor('regressions', $regressions >= 3 ? 2 : ($regressions > 0 ? 1 : 0),
                "{$regressions} of the issues in this release are regressions."),

            $this->factor('recurring', (int) ($r['recurring_count'] ?? 0) >= 3 ? 1 : 0,
                sprintf('%d issues belong to known recurring patterns.', $r['recurring_count'] ?? 0)),

            // Null pass rate is UNKNOWN, not good. Scoring 0 is deliberate: absence
            // of QA evidence should not read as a clean bill of health, but
            // inventing a penalty from missing data would be worse.
            $this->factor('qa_pass_rate',
                $passRate === null ? 0 : ($passRate < 0.7 ? 2 : ($passRate < 0.85 ? 1 : 0)),
                $passRate !== null
                    ? sprintf('QA passed only %d%% of its runs for this release.', (int) round($passRate * 100))
                    : ''),

            $this->factor('size', $total >= 50 ? 2 : ($total >= 20 ? 1 : 0),
                "{$total} issues in one release — larger releases are harder to diagnose when something goes wrong."),

            $this->factor('history', $history >= 2 ? 2 : ($history >= 1 ? 1 : 0),
                "{$history} recent release(s) of this type caused regressions."),
        ]);
    }

    /** Absence of evidence, stated as such rather than as reassurance. */
    public function isUnevidenced(array $result): bool
    {
        return $result['factors'] === [];
    }

    /** A factor that did not fire is dropped, not listed at zero. */
    private function factor(string $key, int $weight, string $detail): ?array
    {
        return $weight === 0 ? null : ['key' => $key, 'weight' => $weight, 'detail' => $detail];
    }

    private function assemble(array $factors): array
    {
        $fired = array_values(array_filter($factors));

        // Heaviest first: the reader should meet the strongest argument immediately.
        usort($fired, fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

        $score = array_sum(array_column($fired, 'weight'));

        return [
            'score'   => $score,
            'level'   => $this->band(max(0, $score)),
            'factors' => $fired,
            'reasons' => array_column($fired, 'detail'),
        ];
    }
}
