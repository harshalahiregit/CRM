import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  tokenize, jaccard, structuralScore, scoreCandidate, rankCandidates, labelFor, LABEL_THRESHOLDS,
} from './reference/similarity.mjs';

const spec = JSON.parse(readFileSync('tests/fixtures/similarity-cases.json', 'utf8'));

for (const c of spec.tokenize_cases) {
  test(`tokenize: ${c.name}`, () => {
    assert.deepEqual(tokenize(c.input).sort(), [...c.expect].sort());
  });
}

for (const c of spec.score_cases) {
  test(`score: ${c.name}`, () => {
    const opts = {};
    if (c.rare_tokens) opts.rareTokens = new Set(c.rare_tokens);
    if (c.now) opts.now = c.now;

    const r = scoreCandidate(c.query, c.candidate, opts);

    for (const [key, expected] of Object.entries(c.expect)) {
      if (key.startsWith('_')) continue; // `_working` documents the arithmetic
      if (key === 'score') { assert.equal(Number(r.score.toFixed(4)), expected, 'score'); continue; }
      if (key === 'label') { assert.equal(r.label, expected, 'label'); continue; }
      if (Array.isArray(expected)) { assert.deepEqual(r.signals[key], expected, key); continue; }
      assert.equal(r.signals[key], expected, key);
    }
  });
}

for (const c of spec.ranking_cases) {
  test(`ranking: ${c.name}`, () => {
    const ranked = rankCandidates(c.query, c.candidates);
    const ids = ranked.map((r) => r.candidate.id);

    assert.deepEqual(ids, c.expect.returned_ids, 'order and membership');
    for (const excluded of c.expect.excluded_ids) {
      assert.ok(!ids.includes(excluded), `${excluded} should be below threshold`);
    }
    if (c.expect.descending) {
      for (let i = 1; i < ranked.length; i += 1) {
        assert.ok(ranked[i - 1].score >= ranked[i].score, 'scores must descend');
      }
    }
  });
}

test('jaccard treats two empty sets as no evidence, not perfect agreement', () => {
  assert.equal(jaccard([], []), 0, 'no evidence is not agreement');
  assert.equal(jaccard(['a'], []), 0);
  assert.equal(jaccard(['a', 'b'], ['a', 'b']), 1);
  assert.equal(jaccard(['a', 'b'], ['b', 'c']), 1 / 3);
});

test('scores are always in range and never NaN, however sparse the input', () => {
  const cases = [
    [{}, {}],
    [{ title: null }, { title: undefined }],
    [{ title: 'x' }, {}],
    [{ title: 'a'.repeat(5000) }, { title: 'a'.repeat(5000) }],
  ];
  for (const [q, c] of cases) {
    const r = scoreCandidate(q, c);
    assert.ok(Number.isFinite(r.score), 'finite');
    assert.ok(r.score >= 0 && r.score <= 1, `in range: ${r.score}`);
  }
});

test('the boost cannot push a score above 1', () => {
  const same = { title: 'leadpolicy deref crash', description: 'stack trace', module: 'sales', section: 'leads', screen: 'lead-details', category: 'bug' };
  const r = scoreCandidate(same, same, { rareTokens: new Set(['leadpolicy', 'deref']) });
  assert.equal(r.score, 1, 'clamped');
});

test('labels line up with the documented thresholds, at the boundaries', () => {
  assert.equal(labelFor(LABEL_THRESHOLDS.very_likely), 'very_likely');
  assert.equal(labelFor(LABEL_THRESHOLDS.very_likely - 0.0001), 'likely');
  assert.equal(labelFor(LABEL_THRESHOLDS.likely), 'likely');
  assert.equal(labelFor(LABEL_THRESHOLDS.possible), 'possible');
  assert.equal(labelFor(LABEL_THRESHOLDS.possible - 0.0001), 'below_threshold');
});

test('scoring is symmetric — which issue was filed first must not change the score', () => {
  const a = { title: 'lead save fail', description: 'spinner', module: 'sales', section: 'leads' };
  const b = { title: 'save lead error', description: 'spinner runs', module: 'sales', section: 'leads' };
  assert.equal(scoreCandidate(a, b).score, scoreCandidate(b, a).score);
});

test('every returned candidate carries evidence a human can read', () => {
  const q = { title: 'lead save fail 500', description: 'spinner', module: 'sales', section: 'leads', screen: 'lead-details', category: 'bug' };
  const ranked = rankCandidates(q, [{ id: 1, ...q }]);

  const { signals } = ranked[0];
  // "92% similar" is not evidence. These are.
  assert.ok(signals.shared_terms.length > 0, 'which terms matched');
  assert.ok(signals.structural_matched.length > 0, 'which fields matched');
  assert.ok('title_similarity' in signals && 'body_similarity' in signals);
});

test('a below-threshold candidate is never returned', () => {
  const q = { title: 'completely unrelated words here', module: 'sales' };
  const ranked = rankCandidates(q, [{ id: 1, title: 'payroll export crash', module: 'hr' }]);
  assert.deepEqual(ranked, []);
});
