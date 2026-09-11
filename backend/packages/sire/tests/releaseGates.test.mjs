import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { evaluateGates, failingGateKeys, DEFAULT_GATES, GATE_STATUS } from './reference/releaseGates.mjs';

const spec = JSON.parse(readFileSync('tests/fixtures/release-gates-cases.json', 'utf8'));

test('the fixture default gates match the reference default gates', () => {
  assert.deepEqual(
    spec.default_gates.map((g) => g.key),
    DEFAULT_GATES.map((g) => g.key),
  );
});

for (const c of spec.cases) {
  test(`gates: ${c.name}`, () => {
    const result = evaluateGates(c.gates, c.snapshot, c.override ?? null);

    for (const [key, expected] of Object.entries(c.expect)) {
      if (key === 'gate_status') {
        for (const [gateKey, gateExpected] of Object.entries(expected)) {
          const gate = result.gates.find((g) => g.key === gateKey);
          assert.ok(gate, `gate ${gateKey} missing from result`);
          assert.equal(gate.status, gateExpected, `${gateKey}.status`);
        }
        continue;
      }
      assert.equal(result[key], expected, key);
    }
  });
}

test('the four default gates the brief names are all present', () => {
  for (const key of ['no_open_critical', 'qa_failures_resolved', 'approvals_complete', 'regression_testing_complete']) {
    assert.ok(DEFAULT_GATES.some((g) => g.key === key), `missing default gate: ${key}`);
  }
});

test('an unknown gate key blocks rather than passing quietly', () => {
  // A typo in settings must not silently disable governance.
  const r = evaluateGates([{ key: 'nope', enabled: true, blocking: true }], { total_issues: 1 });
  assert.equal(r.status, 'blocked');
  assert.equal(r.gates[0].status, GATE_STATUS.UNKNOWN);
});

test('a disabled gate and an advisory gate are different things', () => {
  const snapshot = { total_issues: 4, open_critical: 3 };

  const disabled = evaluateGates([{ key: 'no_open_critical', enabled: false, blocking: true }], snapshot);
  assert.equal(disabled.gates[0].status, GATE_STATUS.SKIPPED);
  assert.equal(disabled.advisory_failures, 0, 'a disabled gate is not evaluated at all');

  const advisory = evaluateGates([{ key: 'no_open_critical', enabled: true, blocking: false }], snapshot);
  assert.equal(advisory.gates[0].status, GATE_STATUS.FAIL);
  assert.equal(advisory.advisory_failures, 1, 'an advisory gate still reports what it found');
  assert.equal(advisory.status, 'ready');
});

test('override_active is false when the override was not load-bearing', () => {
  // Recording an override that changed nothing is fine; implying the release
  // shipped on one is not.
  const r = evaluateGates(null, { total_issues: 2, open_critical: 0 }, { gates: ['no_open_critical'] });
  assert.equal(r.override_active, false);
  assert.equal(r.status, 'ready');
});

test('failingGateKeys names exactly what an override would have to cover', () => {
  const snapshot = { total_issues: 9, open_critical: 1, qa_failed: 2, pending_approvals: 0, regression_required: 0, regression_tested: 0 };
  const keys = failingGateKeys(evaluateGates(null, snapshot));
  assert.deepEqual(keys.sort(), ['no_open_critical', 'qa_failures_resolved']);

  const covered = evaluateGates(null, snapshot, { gates: keys });
  assert.equal(covered.status, 'ready', 'covering every failing key must unblock');
});

test('every gate result carries a human-readable detail line', () => {
  const r = evaluateGates(null, { total_issues: 5, open_critical: 2, qa_failed: 0, pending_approvals: 0, regression_required: 3, regression_tested: 1 });
  for (const gate of r.gates) {
    assert.ok(typeof gate.detail === 'string' && gate.detail.length > 0, `${gate.key} has no detail`);
  }
  // The counts matter more than the verdict — "2 open critical issues" tells you
  // what to do; "blocked" does not.
  assert.match(r.gates.find((g) => g.key === 'no_open_critical').detail, /2 open/);
  assert.match(r.gates.find((g) => g.key === 'regression_testing_complete').detail, /1\/3/);
});

test('an ungoverned release says so instead of showing a confident tick', () => {
  const r = evaluateGates([], { total_issues: 5, open_critical: 3 });
  assert.equal(r.status, 'ready');
  assert.equal(r.ungoverned, true, 'the UI needs to distinguish "passed" from "nothing was checked"');
});

test('evaluation never throws on a sparse snapshot', () => {
  assert.doesNotThrow(() => evaluateGates(null, {}));
  const r = evaluateGates(null, {});
  assert.equal(r.status, 'ready');
  assert.equal(r.empty, true);
});
