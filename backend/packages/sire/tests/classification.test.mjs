import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { classify, vote, overallConfidence, MIN_CONFIDENCE, MIN_NEIGHBOURS } from './reference/classification.mjs';

const spec = JSON.parse(readFileSync('tests/fixtures/classification-cases.json', 'utf8'));

for (const c of spec.cases) {
  test(`classify: ${c.name}`, () => {
    const result = classify({}, c.neighbours, { capturedModule: c.captured_module ?? null });

    for (const [field, expected] of Object.entries(c.expect)) {
      if (field === 'overall_confidence') {
        assert.equal(overallConfidence(result), expected, 'overall_confidence');
        continue;
      }
      for (const [key, value] of Object.entries(expected)) {
        assert.equal(result[field][key], value, `${field}.${key}`);
      }
    }
  });
}

test('every non-abstaining recommendation carries a reason a person can check', () => {
  const neighbours = Array.from({ length: 5 }, () => ({ weight: 1, category: 'bug', severity: 'major', priority: 'p2' }));
  const result = classify({}, neighbours);

  for (const field of ['category', 'severity', 'priority']) {
    assert.ok(result[field].reason.length > 0, `${field} has no reason`);
    // "5 of 5 similar issues were logged as bug" — checkable against the list the
    // user is shown. "Confidence: 87%" is not.
    assert.match(result[field].reason, /\d+ of \d+/, `${field} reason should be countable`);
  }
});

test('an abstention explains itself rather than returning a bare null', () => {
  const result = classify({}, []);
  assert.match(result.category.reason, /No similar issues/);

  const one = classify({}, [{ weight: 1, category: 'bug' }]);
  assert.match(one.category.reason, /not enough/i);
});

test('confidence is never above 1 and never below 0', () => {
  const heavy = Array.from({ length: 50 }, () => ({ weight: 99, category: 'bug' }));
  const r = vote(heavy, 'category');
  assert.ok(r.confidence <= 1 && r.confidence >= 0, `out of range: ${r.confidence}`);
});

test('negative or missing weights cannot invert the vote', () => {
  const r = vote([
    { weight: -5, category: 'security' },
    { weight: 1, category: 'bug' },
    { weight: 1, category: 'bug' },
  ], 'category');
  assert.equal(r.value, 'bug');
});

test('all-zero weights abstain rather than dividing by zero', () => {
  const r = vote([{ weight: 0, category: 'bug' }, { weight: 0, category: 'bug' }], 'category');
  assert.equal(r.abstained, true);
  assert.ok(Number.isFinite(r.confidence));
});

test('the abstention threshold is enforced exactly', () => {
  // Four-way even split across 4 neighbours: share 0.25, coverage 0.8 → 0.2.
  const r = vote([
    { weight: 1, severity: 'a' }, { weight: 1, severity: 'b' },
    { weight: 1, severity: 'c' }, { weight: 1, severity: 'd' },
  ], 'severity');
  assert.equal(r.confidence, 0.2);
  assert.ok(r.confidence < MIN_CONFIDENCE);
  assert.equal(r.abstained, true);
});

test('a tally is returned so the human can see the runners-up', () => {
  const r = vote([
    { weight: 1, category: 'bug' }, { weight: 1, category: 'bug' },
    { weight: 1, category: 'change' },
  ], 'category');
  assert.equal(r.tally.length, 2);
  assert.equal(r.tally[0].value, 'bug');
  assert.equal(r.tally[1].value, 'change');
});

test('classification never invents a value that no neighbour held', () => {
  const neighbours = [
    { weight: 1, category: 'bug' }, { weight: 1, category: 'bug' }, { weight: 1, category: 'change' },
  ];
  const held = new Set(neighbours.map((n) => n.category));
  const r = classify({}, neighbours);
  assert.ok(r.category.value === null || held.has(r.category.value));
});

test('MIN_NEIGHBOURS is honoured for every field independently', () => {
  const r = classify({}, [
    { weight: 1, category: 'bug', severity: 'major' },
    { weight: 1, category: 'bug' },
  ]);
  assert.equal(r.category.abstained, false, '2 neighbours have a category');
  assert.equal(r.severity.abstained, true, 'only 1 has a severity');
  assert.equal(MIN_NEIGHBOURS, 2);
});
