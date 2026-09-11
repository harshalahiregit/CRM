import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { computeSla, overallState, resolvePolicy } from './reference/slaPolicy.mjs';

const spec = JSON.parse(readFileSync('tests/fixtures/sla-cases.json', 'utf8'));

for (const c of spec.cases) {
  test(`SLA: ${c.name}`, () => {
    const result = computeSla({
      policies: c.policies,
      severity: c.severity,
      report: c.report,
      now: c.now,
      warningThreshold: spec.defaults.warning_threshold,
      pauseStates: spec.defaults.pause_states,
    });

    if (c.expect.source !== undefined) assert.equal(result.source, c.expect.source, 'source');
    if (c.expect.matched_index !== undefined) assert.equal(result.matched_index, c.expect.matched_index, 'matched_index');

    for (const clock of ['ack', 'resolve']) {
      if (!c.expect[clock]) continue;
      for (const [key, expected] of Object.entries(c.expect[clock])) {
        if (key === 'deadline') {
          // '...T10:00:00Z' and '...T10:00:00.000Z' are the same instant.
          assert.equal(Date.parse(result[clock][key]), Date.parse(expected), `${clock}.${key}`);
          continue;
        }
        assert.equal(result[clock][key], expected, `${clock}.${key}`);
      }
    }
  });
}

test('policy specificity beats list order in both directions', () => {
  const specific = { match: { type: 'bug', priority: 'p1' }, ack_minutes: 15 };
  const broad = { match: {}, ack_minutes: 480 };
  const report = { type: 'bug', priority: 'p1' };
  assert.equal(resolvePolicy([broad, specific], report).ack_minutes, 15);
  assert.equal(resolvePolicy([specific, broad], report).ack_minutes, 15);
});

test('a policy whose match does not apply is skipped entirely', () => {
  const report = { type: 'enhancement', priority: 'p3', severity: 'minor' };
  assert.equal(resolvePolicy([{ match: { type: 'bug' }, ack_minutes: 1 }], report), null);
});

test('overallState reports the worse of the two clocks', () => {
  assert.equal(overallState({ ack: { state: 'on_track' }, resolve: { state: 'breached' } }), 'breached');
  assert.equal(overallState({ ack: { state: 'warning' }, resolve: { state: 'on_track' } }), 'warning');
  assert.equal(overallState({ ack: { state: 'paused' }, resolve: { state: 'on_track' } }), 'paused');
  assert.equal(overallState({ ack: { state: null }, resolve: { state: null } }), null);
});

test('an already-breached clock stays breached even if the issue is then paused', () => {
  const r = computeSla({
    policies: [{ match: {}, ack_minutes: 60, resolve_minutes: 100 }],
    severity: {},
    report: { type: 'bug', priority: 'p1', status: 'on_hold', sla_started_at: '2026-03-01T09:00:00Z', acknowledged_at: '2026-03-01T09:05:00Z', closed_at: null, sla_paused_minutes: 0, sla_paused_since: '2026-03-01T15:00:00Z' },
    now: '2026-03-01T16:00:00Z',
    pauseStates: spec.defaults.pause_states,
  });
  // 09:00 -> 15:00 unpaused = 360 min against a 100 min target.
  assert.equal(r.resolve.state, 'breached', 'pausing must not un-breach an existing breach');
});

test('elapsed never goes negative when the accumulator exceeds wall time', () => {
  const r = computeSla({
    policies: [{ match: {}, ack_minutes: 60, resolve_minutes: 100 }],
    severity: {},
    report: { type: 'bug', priority: 'p1', status: 'in_development', sla_started_at: '2026-03-01T09:00:00Z', acknowledged_at: null, closed_at: null, sla_paused_minutes: 99999, sla_paused_since: null },
    now: '2026-03-01T10:00:00Z',
    pauseStates: spec.defaults.pause_states,
  });
  assert.equal(r.resolve.elapsed_minutes, 0);
  assert.equal(r.resolve.state, 'on_track');
});

test('the four states the brief names are the only states ever produced', () => {
  const allowed = new Set(['on_track', 'warning', 'breached', 'paused', null]);
  for (const c of spec.cases) {
    const r = computeSla({
      policies: c.policies, severity: c.severity, report: c.report, now: c.now,
      warningThreshold: spec.defaults.warning_threshold, pauseStates: spec.defaults.pause_states,
    });
    assert.ok(allowed.has(r.ack.state), `unexpected ack state ${r.ack.state}`);
    assert.ok(allowed.has(r.resolve.state), `unexpected resolve state ${r.resolve.state}`);
  }
});
