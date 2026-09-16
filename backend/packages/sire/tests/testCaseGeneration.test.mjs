import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { generate, categoriesFor, CATEGORY } from './reference/testCaseGenerator.mjs';
import { suggest, confirmedNeighbours } from './reference/rootCauseSuggestion.mjs';

const spec = JSON.parse(readFileSync('tests/fixtures/test-case-generation-cases.json', 'utf8'));

const find = (tests, category) => tests.find((t) => t.category === category);

for (const c of spec.cases) {
  test(`generate: ${c.name}`, () => {
    const tests = generate(c.issue, c.opts ?? {});
    const categories = tests.map((t) => t.category);
    const e = c.expect;

    if (e.categories) assert.deepEqual(categories, e.categories, 'categories');
    if (e.count !== undefined) assert.equal(tests.length, e.count, 'count');
    for (const inc of e.includes ?? []) assert.ok(categories.includes(inc), `should include ${inc}`);
    for (const exc of e.excludes ?? []) assert.ok(!categories.includes(exc), `should exclude ${exc}`);

    if (e.boundary_mentions) assert.match(find(tests, CATEGORY.BOUNDARY).when, new RegExp(e.boundary_mentions));
    if (e.permission_rationale_mentions) assert.match(find(tests, CATEGORY.PERMISSION).rationale, new RegExp(e.permission_rationale_mentions));
    if (e.regression_rationale_mentions) assert.match(find(tests, CATEGORY.REGRESSION).rationale, new RegExp(e.regression_rationale_mentions));
    if (e.regression_title_mentions) assert.match(find(tests, CATEGORY.REGRESSION).title, new RegExp(e.regression_title_mentions));
    if (e.related_mentions) assert.match(find(tests, CATEGORY.RELATED_WORKFLOW).when, new RegExp(e.related_mentions));
  });
}

test('a generated test NEVER carries a result', () => {
  // The single most important assertion in this file. A generated checklist that
  // could mark itself passed would be worse than no checklist.
  for (const c of spec.cases) {
    for (const t of generate(c.issue, c.opts ?? {})) {
      assert.equal(t.result, null, `${t.category} arrived with a result`);
      assert.equal(t.status, 'draft', `${t.category} arrived active`);
      assert.equal(t.source, 'ai_suggested');
    }
  }
});

test('every generated test carries a rationale saying why it exists', () => {
  const tests = generate({ title: 'Staff get a 403 over 5000 chars', module: 'sales', section: 'leads', entity_type: 'lead', is_regression: true });
  for (const t of tests) {
    assert.ok(t.rationale?.length > 0, `${t.category} has no rationale`);
  }
  // The conditional ones say what triggered them, so a reader can disagree.
  assert.match(find(tests, CATEGORY.BOUNDARY).rationale, /Emitted because/);
  assert.match(find(tests, CATEGORY.PERMISSION).rationale, /Emitted because/);
});

test('generation quotes the issue rather than paraphrasing it', () => {
  const steps = 'Open a lead, paste a long note, press Save';
  const expected = 'The lead saves and the note appears';
  const tests = generate({ title: 'x', module: 'sales', steps_to_reproduce: steps, expected_result: expected });

  assert.equal(find(tests, CATEGORY.HAPPY_PATH).when, steps);
  assert.equal(find(tests, CATEGORY.HAPPY_PATH).then, expected);
});

test('a very long quotation is truncated, not dropped', () => {
  const long = 'x'.repeat(1000);
  const tests = generate({ title: 't', module: 'sales', steps_to_reproduce: long });
  const when = find(tests, CATEGORY.HAPPY_PATH).when;
  assert.ok(when.length <= 240, `length ${when.length}`);
  assert.ok(when.endsWith('…'), 'truncation should be visible');
});

test('generation never throws on a sparse or hostile issue', () => {
  for (const issue of [{}, { title: null }, { title: '<script>alert(1)</script>' }, { description: 'a'.repeat(50000) }]) {
    assert.doesNotThrow(() => generate(issue));
    assert.ok(generate(issue).length >= 2, 'the two mandatory tests always appear');
  }
});

test('categoriesFor agrees with generate', () => {
  const issue = { title: 'Staff 403 over 5000 chars', module: 'sales', section: 'leads', is_regression: true };
  assert.deepEqual(categoriesFor(issue), generate(issue).map((t) => t.category));
});

// ---------------------------------------------------------------- root cause

const rc = (ref, weight, category, description, extra = {}) => ({
  ref, weight,
  root_cause: { category, description, confirmed_at: '2026-01-01T00:00:00Z', ...extra },
});

test('root cause: an unconfirmed analysis is not evidence', () => {
  const neighbours = [
    { ref: 'SIR-1', weight: 0.9, root_cause: { category: 'code', description: 'x', confirmed_at: null } },
    { ref: 'SIR-2', weight: 0.8, root_cause: { category: 'code', description: 'y', confirmed_at: null } },
  ];
  assert.equal(confirmedNeighbours(neighbours).length, 0);
  assert.equal(suggest({}, neighbours).abstained, true);
});

test('root cause: fewer than two confirmed neighbours abstains and says why', () => {
  const r = suggest({}, [rc('SIR-1', 0.9, 'code', 'Missing null check')]);
  assert.equal(r.abstained, true);
  assert.match(r.reason, /Only one similar issue/);
  assert.equal(r.description, null);
});

test('root cause: the description is QUOTED and attributed, never invented', () => {
  const r = suggest({}, [
    rc('SIR-1', 0.9, 'code', 'Missing null check in LeadPolicy'),
    rc('SIR-2', 0.7, 'code', 'Unguarded array access'),
    rc('SIR-3', 0.6, 'code', 'Off-by-one in pagination'),
  ]);

  assert.equal(r.abstained, false);
  assert.equal(r.category, 'code');
  // Quoted from the CLOSEST agreeing neighbour, and attributed by number.
  assert.equal(r.quoted_from, 'SIR-1');
  assert.equal(r.description, 'Missing null check in LeadPolicy');
  assert.deepEqual(r.related, ['SIR-1', 'SIR-2', 'SIR-3']);
});

test('root cause: the quotation comes from a neighbour in the WINNING category', () => {
  // The closest neighbour overall disagrees with the vote. Quoting it would
  // attribute a description to a category it does not belong to.
  const r = suggest({}, [
    rc('SIR-1', 0.95, 'data', 'Corrupt import row'),
    rc('SIR-2', 0.80, 'code', 'Missing null check'),
    rc('SIR-3', 0.75, 'code', 'Unguarded array access'),
    rc('SIR-4', 0.70, 'code', 'Off-by-one'),
  ]);

  assert.equal(r.category, 'code');
  assert.equal(r.quoted_from, 'SIR-2', 'closest AGREEING neighbour, not closest overall');
});

test('root cause: only factors seen more than once are offered as a pattern', () => {
  const r = suggest({}, [
    rc('SIR-1', 0.9, 'code', 'a', { contributing_factors: ['no test coverage', 'rushed release'] }),
    rc('SIR-2', 0.8, 'code', 'b', { contributing_factors: ['no test coverage'] }),
    rc('SIR-3', 0.7, 'code', 'c', { contributing_factors: ['unrelated one-off'] }),
  ]);

  assert.deepEqual(r.contributing_factors, ['no test coverage']);
});

test('root cause: the result never claims to be confirmed', () => {
  const r = suggest({}, [rc('SIR-1', 0.9, 'code', 'x'), rc('SIR-2', 0.8, 'code', 'y')]);
  assert.ok(!('confirmed' in r), 'a suggestion must not carry a confirmation flag');
  assert.ok(!('confirmed_at' in r));
  assert.ok('quoted_from' in r, 'it carries attribution instead');
});

test('root cause: disagreeing neighbours abstain rather than pick a coin flip', () => {
  const r = suggest({}, [
    rc('SIR-1', 1, 'code', 'a'), rc('SIR-2', 1, 'data', 'b'),
    rc('SIR-3', 1, 'process', 'c'), rc('SIR-4', 1, 'design', 'd'),
  ]);
  assert.equal(r.abstained, true);
  assert.equal(r.category, null);
  assert.deepEqual(r.related.length, 4, 'the neighbours are still offered to read');
});
