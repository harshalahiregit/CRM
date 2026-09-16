import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { recurrenceRisk, averageIntervalDays, computeMetric, rate } from './reference/quality.mjs';

const riskSpec = JSON.parse(readFileSync('tests/fixtures/recurrence-risk-cases.json', 'utf8'));
const metricSpec = JSON.parse(readFileSync('tests/fixtures/quality-metrics-cases.json', 'utf8'));

for (const c of riskSpec.cases) {
  test(`recurrence risk: ${c.name}`, () => {
    const result = recurrenceRisk(c.group, c.now);
    for (const [key, expected] of Object.entries(c.expect)) {
      assert.equal(result[key], expected, key);
    }
  });
}

for (const c of metricSpec.cases) {
  test(`metric: ${c.name}`, () => {
    const result = computeMetric(c.metric, c.input);
    for (const [key, expected] of Object.entries(c.expect)) {
      assert.equal(result[key], expected, key);
    }
  });
}

test('every risk value is one of the four documented levels', () => {
  const allowed = new Set(['low', 'medium', 'high', 'critical']);
  for (const c of riskSpec.cases) {
    assert.ok(allowed.has(recurrenceRisk(c.group, c.now).risk));
  }
});

test('risk is monotonic in occurrence count, all else equal', () => {
  const base = { average_interval_days: 20, permanent_fix_status: 'none', latest_occurrence_at: '2026-05-30T00:00:00Z' };
  const now = '2026-06-01T00:00:00Z';
  let previous = -1;
  for (const count of [2, 3, 5, 10, 50]) {
    const { score } = recurrenceRisk({ ...base, occurrence_count: count }, now);
    assert.ok(score >= previous, `score fell as occurrences rose: ${count}`);
    previous = score;
  }
});

test('shipping a fix can only lower risk, never raise it', () => {
  const base = { occurrence_count: 5, average_interval_days: 10, latest_occurrence_at: '2026-05-30T00:00:00Z' };
  const now = '2026-06-01T00:00:00Z';
  const none = recurrenceRisk({ ...base, permanent_fix_status: 'none' }, now).score;
  const planned = recurrenceRisk({ ...base, permanent_fix_status: 'planned' }, now).score;
  const shipped = recurrenceRisk({ ...base, permanent_fix_status: 'shipped' }, now).score;
  assert.ok(none >= planned && planned >= shipped);
});

test('averageIntervalDays needs at least two occurrences', () => {
  assert.equal(averageIntervalDays([]), null);
  assert.equal(averageIntervalDays(['2026-01-01T00:00:00Z']), null);
  assert.equal(averageIntervalDays(['2026-01-01T00:00:00Z', '2026-01-11T00:00:00Z']), 10);
  // Unsorted input must not produce a negative mean.
  assert.equal(averageIntervalDays(['2026-01-21T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-11T00:00:00Z']), 10);
});

test('a zero denominator never yields NaN, Infinity or 0%', () => {
  for (const [n, d] of [[0, 0], [5, 0], [0, null], [3, undefined]]) {
    const r = rate(n, d);
    assert.equal(r.value, null);
    assert.equal(r.display, '—');
    assert.ok(!Number.isNaN(r.value));
  }
});

test('a real zero is reported as 0%, which is a different claim from no data', () => {
  const r = rate(0, 40);
  assert.equal(r.value, 0);
  assert.equal(r.display, '0%');
  assert.equal(r.sample, 40);
});

test('small samples are flagged so a headline percentage cannot mislead', () => {
  assert.equal(rate(1, 3).low_confidence, true);
  assert.equal(rate(1, 40).low_confidence, false);
});
