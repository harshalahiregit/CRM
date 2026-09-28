import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { regressionRisk, recurrenceRisk, releaseRisk, band, isUnevidenced, THRESHOLDS, BAND } from './reference/riskEngines.mjs';

const spec = JSON.parse(readFileSync('tests/fixtures/risk-engine-cases.json', 'utf8'));

const ENGINES = {
  regression_cases: regressionRisk,
  recurrence_cases: recurrenceRisk,
  release_cases: releaseRisk,
};

for (const [group, engine] of Object.entries(ENGINES)) {
  for (const c of spec[group]) {
    test(`${group.replace('_cases', '')}: ${c.name}`, () => {
      const r = engine(c.input);
      const e = c.expect;
      const keys = r.factors.map((f) => f.key);

      if (e.score !== undefined) assert.equal(r.score, e.score, 'score');
      if (e.level) assert.equal(r.level, e.level, 'level');
      if (e.factor_count !== undefined) assert.equal(r.factors.length, e.factor_count, 'factor count');
      if (e.unevidenced !== undefined) assert.equal(isUnevidenced(r), e.unevidenced, 'unevidenced');
      if (e.top_factor) assert.equal(keys[0], e.top_factor, 'heaviest factor first');
      if (e.excludes_factor) assert.ok(!keys.includes(e.excludes_factor), `should exclude ${e.excludes_factor}`);
      for (const k of e.includes_factors ?? []) assert.ok(keys.includes(k), `should include ${k}`);
    });
  }
}

test('the thresholds in the fixture match the code', () => {
  assert.deepEqual(THRESHOLDS, spec.thresholds);
});

test('one banding scale serves all three engines', () => {
  // A HIGH on a release and a HIGH on an issue must mean a comparable weight of
  // evidence, or the word stops carrying information.
  assert.equal(band(THRESHOLDS.high), BAND.HIGH);
  assert.equal(band(THRESHOLDS.high - 1), BAND.MEDIUM);
  assert.equal(band(THRESHOLDS.medium), BAND.MEDIUM);
  assert.equal(band(THRESHOLDS.medium - 1), BAND.LOW);
  assert.equal(band(0), BAND.LOW);
  assert.equal(band(-5), BAND.LOW);
});

test('every engine returns only the three bands the brief names', () => {
  const allowed = new Set(['low', 'medium', 'high']);
  for (const [group, engine] of Object.entries(ENGINES)) {
    for (const c of spec[group]) assert.ok(allowed.has(engine(c.input).level));
  }
});

test('every factor that fires carries a sentence, and none that does not is listed', () => {
  const r = regressionRisk({ module: 'sales', module_regression_rate: 0.3, module_issue_count: 10, severity_code: 'critical', active_test_count: 0 });

  for (const f of r.factors) {
    assert.ok(f.weight !== 0, 'a zero-weight factor should be dropped, not shown');
    assert.ok(f.detail?.length > 10, `${f.key} has no usable reason`);
  }
  assert.equal(r.reasons.length, r.factors.length);
});

test('reasons arrive heaviest-first so the strongest argument is read first', () => {
  const r = releaseRisk({ open_critical: 1, qa_failed: 3, regression_count: 5, total_issues: 60, qa_pass_rate: 0.5, prior_releases_caused_regressions: 2, recurring_count: 4 });
  const weights = r.factors.map((f) => f.weight);
  for (let i = 1; i < weights.length; i += 1) assert.ok(weights[i - 1] >= weights[i], 'descending');
});

test('no engine throws on an empty input, and an empty input is LOW and unevidenced', () => {
  for (const engine of Object.values(ENGINES)) {
    assert.doesNotThrow(() => engine({}));
    assert.doesNotThrow(() => engine());
    const r = engine({});
    assert.equal(r.level, BAND.LOW);
    // Silence, not reassurance: nothing was found, and the caller can say so.
    assert.equal(isUnevidenced(r), true);
  }
});

test('recurrence is the only engine with a negative factor, and that is deliberate', () => {
  // A verified permanent fix is real evidence AGAINST recurrence and should pull
  // a score down. Nothing else in any engine argues for lower risk.
  const negatives = (r) => r.factors.filter((f) => f.weight < 0).map((f) => f.key);

  assert.deepEqual(negatives(recurrenceRisk({ occurrence_count: 3, permanent_fix_status: 'verified' })), ['permanent_fix_verified']);
  assert.deepEqual(negatives(regressionRisk({ module_issue_count: 10, module_regression_rate: 0.3 })), []);
  assert.deepEqual(negatives(releaseRisk({ open_critical: 1 })), []);
});

test('release risk never claims to be a gate', () => {
  // Advisory only, per the capability catalogue. It reports; gates block.
  const r = releaseRisk({ open_critical: 5, qa_failed: 9, regression_count: 9, total_issues: 90, qa_pass_rate: 0.1 });
  assert.equal(r.level, 'high');
  assert.ok(!('blocking' in r), 'a risk opinion must not carry a blocking flag');
  assert.ok(!('gate' in r));
});

test('the two recurrence opinions are computed from different inputs on purpose', () => {
  // SireRecurrenceService scores a GROUP from cadence and fix status; this scores
  // an ISSUE from reopens, repeated fixes and workaround language. A second
  // opinion that only ever agreed would be worth nothing.
  const r = recurrenceRisk({ occurrence_count: 2, reopen_count: 2, distinct_fix_versions: 2, workaround_signals: ['hotfix'] });
  const keys = r.factors.map((f) => f.key);
  assert.ok(keys.includes('reopened'));
  assert.ok(keys.includes('repeated_fixes'));
  assert.ok(keys.includes('workaround_language'));
  // None of these are inputs to the group formula.
  assert.ok(!keys.includes('average_interval_days'));
});

test('zero tests and unmeasured tests are different claims', () => {
  // Firing on absent data would let an engine handed nothing announce a finding.
  const measured = regressionRisk({ active_test_count: 0, module_issue_count: 0 });
  const unmeasured = regressionRisk({ module_issue_count: 0 });

  assert.ok(measured.factors.some((f) => f.key === 'no_tests'), 'zero tests is a finding');
  assert.ok(!unmeasured.factors.some((f) => f.key === 'no_tests'), 'unmeasured is not');
});
