/**
 * SIRE — the three risk engines. The executable specification.
 *
 * NOT APPLICATION CODE. Mirrored by SireRegressionRiskService.php,
 * SireRecurrenceRiskService.php and SireReleaseRiskService.php; all run
 * fixtures/risk-engine-cases.json.
 *
 * ONE BANDING SCALE FOR ALL THREE. A "HIGH" on a release and a "HIGH" on an issue
 * should mean a comparable weight of evidence, otherwise the word stops carrying
 * information and people learn to ignore whichever one cries wolf.
 *
 * EVERY FACTOR CARRIES ITS OWN SENTENCE. The brief asks for risk "with reasons",
 * and a reason has to be something a person can disagree with: "3 of the last 14
 * issues in Sales were regressions" is arguable, "risk score 7" is not.
 */

export const BAND = { LOW: 'low', MEDIUM: 'medium', HIGH: 'high' };

/** Shared thresholds. Changing these changes what HIGH means everywhere. */
export const THRESHOLDS = { high: 6, medium: 3 };

export function band(score) {
  if (score >= THRESHOLDS.high) return BAND.HIGH;
  if (score >= THRESHOLDS.medium) return BAND.MEDIUM;
  return BAND.LOW;
}

/** A factor that did not fire is dropped, not listed at zero. */
const factor = (key, weight, detail) => (weight === 0 ? null : { key, weight, detail });

function assemble(factors) {
  const fired = factors.filter(Boolean);
  const score = fired.reduce((s, f) => s + f.weight, 0);

  return {
    score,
    level: band(Math.max(0, score)),
    // Heaviest first: the reader should meet the strongest argument immediately.
    factors: fired.sort((a, b) => b.weight - a.weight),
    reasons: fired.sort((a, b) => b.weight - a.weight).map((f) => f.detail),
  };
}

// ------------------------------------------------------------ regression risk

/**
 * How likely is fixing this issue to break something that worked?
 *
 * @param {object} i  module_regression_rate, module_issue_count, is_regression,
 *                    reopen_count, severity_code, root_cause_category,
 *                    active_test_count, regression_test_required,
 *                    regression_test_done, prior_release_caused_regressions
 */
export function regressionRisk(i = {}) {
  const rate = i.module_regression_rate ?? 0;
  const count = i.module_issue_count ?? 0;

  return assemble([
    // Module history is the strongest single signal — but only once there is
    // enough of it. Two issues in a module is not a track record.
    count >= 5
      ? factor(
          'module_regression_history',
          rate >= 0.2 ? 3 : rate >= 0.1 ? 2 : rate > 0 ? 1 : 0,
          rate > 0
            ? `${Math.round(rate * 100)}% of recent ${i.module ?? 'module'} issues were regressions (${count} looked at).`
            : '',
        )
      : null,

    factor('already_a_regression', i.is_regression ? 2 : 0,
      'This issue is itself a regression — the area has broken under change before.'),

    factor('reopened',
      (i.reopen_count ?? 0) >= 2 ? 2 : (i.reopen_count ?? 0) === 1 ? 1 : 0,
      `Reopened ${i.reopen_count} time(s): earlier fixes here did not hold.`),

    factor('severity',
      i.severity_code === 'critical' ? 2 : i.severity_code === 'major' ? 1 : 0,
      `Severity is ${i.severity_code} — a regression here would be felt immediately.`),

    factor('root_cause_class',
      ['code', 'design'].includes(i.root_cause_category) ? 1 : 0,
      `Root cause was ${i.root_cause_category}: changes of that kind carry more blast radius than data or configuration fixes.`),

    /*
     * The test signal is the one thing the team can act on before shipping, which
     * is why it is weighted like history rather than like a hint.
     *
     * `undefined` means NOT MEASURED and scores nothing — the same rule as an
     * unknown QA pass rate below. Firing on absent data would let an engine that
     * was handed nothing announce a finding, which is how a risk score stops
     * meaning anything.
     */
    factor('no_tests', i.active_test_count === 0 ? 2 : 0,
      'No test cases are recorded against this issue — nothing will catch it coming back.'),

    factor('regression_test_outstanding',
      (i.regression_test_required && !i.regression_test_done) ? 1 : 0,
      'A regression test is required for this issue and has not been run.'),

    factor('prior_release_regressions', i.prior_release_caused_regressions ? 1 : 0,
      'The previous release touching this area caused regressions of its own.'),
  ]);
}

// ------------------------------------------------------------ recurrence risk

/**
 * How likely is this defect to come back?
 *
 * DELIBERATELY DIFFERENT INPUTS from SireRecurrenceService::assessRisk(), which
 * scores a recurrence GROUP from its cadence and fix status. This scores an ISSUE
 * from its own history — reopens, repeated fixes, workaround language.
 *
 * The two are shown side by side and never merged. A deterministic formula you can
 * explain should not be overwritten by a second opinion, and a second opinion that
 * only ever agreed would be worth nothing.
 */
export function recurrenceRisk(i = {}) {
  const occurrences = i.occurrence_count ?? 0;
  const fixes = i.distinct_fix_versions ?? 0;

  return assemble([
    factor('occurrences',
      occurrences >= 5 ? 3 : occurrences >= 3 ? 2 : occurrences === 2 ? 1 : 0,
      `This defect has been recorded ${occurrences} times.`),

    factor('reopened',
      (i.reopen_count ?? 0) >= 2 ? 2 : (i.reopen_count ?? 0) === 1 ? 1 : 0,
      `Reopened ${i.reopen_count} time(s) after being closed.`),

    factor('same_root_cause_repeats',
      (i.same_root_cause_count ?? 0) >= 3 ? 2 : (i.same_root_cause_count ?? 0) >= 1 ? 1 : 0,
      `${i.same_root_cause_count} other issue(s) in this area shared the same root cause category.`),

    // Several fixes shipped for one defect is the clearest evidence that none of
    // them addressed the cause.
    factor('repeated_fixes',
      fixes >= 3 ? 2 : fixes === 2 ? 1 : 0,
      `${fixes} separate releases have shipped a fix for this — none of them held.`),

    factor('workaround_language', i.workaround_signals?.length > 0 ? 2 : 0,
      `The fix is described in terms of a workaround (${(i.workaround_signals ?? []).slice(0, 3).join(', ')}), which tends to defer a defect rather than end it.`),

    factor('no_permanent_fix',
      i.permanent_fix_status === 'none' ? 1 : 0,
      'No permanent fix is planned for the underlying pattern.'),

    // The only negative factor in any engine. A verified permanent fix is real
    // evidence AGAINST recurrence and should be able to pull a score down.
    factor('permanent_fix_verified',
      i.permanent_fix_status === 'verified' ? -2 : 0,
      'A permanent fix has been shipped and verified, which counts against recurrence.'),
  ]);
}

// -------------------------------------------------------------- release risk

/**
 * How risky is shipping this release?
 *
 * ADVISORY ONLY, and never a gate. Gates are deterministic and explainable and
 * BLOCK; this is a judgement and INFORMS. A release must never be stopped by
 * something whose reasoning nobody can reconstruct.
 */
export function releaseRisk(r = {}) {
  const passRate = r.qa_pass_rate;

  return assemble([
    factor('open_critical', (r.open_critical ?? 0) > 0 ? 3 : 0,
      `${r.open_critical} critical issue(s) are still open in this release.`),

    factor('qa_failed', (r.qa_failed ?? 0) > 0 ? 2 : 0,
      `${r.qa_failed} issue(s) are sitting in QA Failed.`),

    factor('regressions',
      (r.regression_count ?? 0) >= 3 ? 2 : (r.regression_count ?? 0) > 0 ? 1 : 0,
      `${r.regression_count} of the issues in this release are regressions.`),

    factor('recurring', (r.recurring_count ?? 0) >= 3 ? 1 : 0,
      `${r.recurring_count} issues belong to known recurring patterns.`),

    // Null pass rate is unknown, not good. Scoring 0 here is deliberate: absence
    // of QA evidence should not read as a clean bill of health, but inventing a
    // penalty from missing data would be worse.
    factor('qa_pass_rate',
      passRate === null || passRate === undefined ? 0 : passRate < 0.7 ? 2 : passRate < 0.85 ? 1 : 0,
      passRate != null ? `QA passed only ${Math.round(passRate * 100)}% of its runs for this release.` : ''),

    factor('size',
      (r.total_issues ?? 0) >= 50 ? 2 : (r.total_issues ?? 0) >= 20 ? 1 : 0,
      `${r.total_issues} issues in one release — larger releases are harder to diagnose when something goes wrong.`),

    factor('history', r.prior_releases_caused_regressions >= 2 ? 2 : r.prior_releases_caused_regressions >= 1 ? 1 : 0,
      `${r.prior_releases_caused_regressions} recent release(s) of this type caused regressions.`),
  ]);
}

/** Absence of evidence, stated as such rather than as reassurance. */
export function isUnevidenced(result) {
  return result.factors.length === 0;
}
