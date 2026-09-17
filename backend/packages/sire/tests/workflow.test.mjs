import { test } from 'node:test';
import assert from 'node:assert/strict';
import wf from '../resources/js/lib/sire/workflow.generated.js';

const STATES = Object.keys(wf.states);
const byAction = new Map(wf.transitions.map((t) => [t.action, t]));
const can = (from, action) => {
  const t = byAction.get(action);
  return Boolean(t && t.from.includes(from));
};

test('every state the brief lists exists', () => {
  const required = [
    'new', 'triaged', 'assigned', 'in_development', 'ready_for_qa', 'qa_in_progress',
    'qa_failed', 'qa_passed', 'ready_for_release', 'released', 'production_validated',
    'closed', 'reopened', 'on_hold', 'duplicate', 'rejected', 'wont_fix', 'cannot_reproduce',
  ];
  for (const s of required) assert.ok(wf.states[s], `missing state: ${s}`);

  // Phase 2 adds the change-request track. Listed separately so a defect-track
  // regression cannot hide behind a bumped total.
  const changeTrack = ['business_review', 'impact_analysis', 'approval', 'planned'];
  for (const s of changeTrack) assert.ok(wf.states[s], `missing change state: ${s}`);

  assert.equal(STATES.length, required.length + changeTrack.length, 'no extra states');
});

test('every transition references states that exist', () => {
  for (const t of wf.transitions) {
    for (const f of t.from) assert.ok(wf.states[f], `${t.action}: unknown from-state ${f}`);
    if (!t.to.startsWith('@')) assert.ok(wf.states[t.to], `${t.action}: unknown to-state ${t.to}`);
    if (t.auto_advance) assert.ok(wf.states[t.auto_advance], `${t.action}: unknown auto_advance ${t.auto_advance}`);
  }
});

test('action keys are unique', () => {
  const keys = wf.transitions.map((t) => t.action);
  assert.equal(new Set(keys).size, keys.length);
});

test('the happy path is walkable end to end', () => {
  const path = [
    ['new', 'triage', 'triaged'],
    ['triaged', 'assign', 'assigned'],
    ['assigned', 'start_development', 'in_development'],
    ['in_development', 'mark_ready_for_qa', 'ready_for_qa'],
    ['ready_for_qa', 'start_qa', 'qa_in_progress'],
    ['qa_in_progress', 'qa_pass', 'qa_passed'],
    ['qa_passed', 'mark_ready_for_release', 'ready_for_release'],
    ['ready_for_release', 'release', 'released'],
    ['released', 'validate_production', 'production_validated'],
    ['production_validated', 'close', 'closed'],
  ];
  for (const [from, action, to] of path) {
    assert.ok(can(from, action), `${from} --${action}--> should be legal`);
    assert.equal(byAction.get(action).to, to);
  }
});

test('QA failure loops back to development and notifies', () => {
  assert.ok(can('qa_in_progress', 'qa_fail'));
  const fail = byAction.get('qa_fail');
  assert.equal(fail.to, 'qa_failed');
  assert.deepEqual(fail.requires, ['qa_notes'], 'a failure without notes is useless to the developer');
  assert.equal(fail.notifies, 'sire.qa.failed');
  assert.ok(can('qa_failed', 'start_development'), 'developer picks it straight back up');
});

test('QA pass auto-advances to ready_for_release', () => {
  const pass = byAction.get('qa_pass');
  assert.equal(pass.to, 'qa_passed');
  assert.equal(pass.auto_advance, 'ready_for_release');
  assert.ok(can('qa_passed', 'mark_ready_for_release'), 'explicit path exists when auto-advance is off');
});

test('illegal transitions are illegal', () => {
  const illegal = [
    ['new', 'release'], ['new', 'close'], ['new', 'start_qa'],
    ['in_development', 'close'], ['in_development', 'release'],
    ['ready_for_qa', 'qa_pass'], ['qa_failed', 'close'],
    ['closed', 'start_development'], ['duplicate', 'assign'],
    ['assigned', 'start_qa'], ['triaged', 'mark_ready_for_qa'],
    ['qa_in_progress', 'hold'],
  ];
  for (const [from, action] of illegal) {
    assert.ok(!can(from, action), `${from} --${action}--> must be rejected`);
  }
});

test('guarded transitions declare what they need', () => {
  assert.deepEqual(byAction.get('triage').requires, ['severity_id', 'priority']);
  assert.deepEqual(byAction.get('assign').requires, ['assignee_id']);
  assert.deepEqual(byAction.get('mark_ready_for_qa').requires, ['fix_summary']);
  assert.deepEqual(byAction.get('release').requires, ['release_ref']);
  assert.deepEqual(byAction.get('hold').requires, ['hold_reason']);
  assert.deepEqual(byAction.get('mark_duplicate').requires, ['duplicate_of_id']);
  for (const a of ['reject', 'wont_fix', 'cannot_reproduce']) {
    assert.deepEqual(byAction.get(a).requires, ['resolution_note'], `${a} must record why`);
  }
});

test('on_hold remembers and restores where it paused', () => {
  const resume = byAction.get('resume');
  assert.equal(resume.to, '@held_from_status');
  assert.deepEqual(resume.from, ['on_hold']);
  assert.ok(!byAction.get('hold').from.includes('new'), 'triage before parking it');
  assert.ok(!byAction.get('hold').from.includes('qa_in_progress'), 'finish the QA run first');
});

test('every terminal state can be reopened, and reopening re-enters triage', () => {
  const reopen = byAction.get('reopen');
  for (const s of STATES.filter((s) => wf.states[s].terminal)) {
    assert.ok(reopen.from.includes(s), `${s} must be reopenable`);
  }
  assert.equal(reopen.to, 'reopened');
  assert.ok(can('reopened', 'triage'));
  assert.ok(can('reopened', 'assign'), 'a known issue can go straight back to its developer');
});

test('every state is reachable from the initial state', () => {
  const seen = new Set([wf.initial]);
  const queue = [wf.initial];
  while (queue.length) {
    const current = queue.shift();
    for (const t of wf.transitions) {
      if (!t.from.includes(current)) continue;
      const targets = t.to === '@held_from_status'
        ? wf.transitions.find((x) => x.action === 'hold').from   // resume can land on any held-from state
        : [t.to];
      for (const target of [...targets, ...(t.auto_advance ? [t.auto_advance] : [])]) {
        if (!seen.has(target)) { seen.add(target); queue.push(target); }
      }
    }
  }
  const unreachable = STATES.filter((s) => !seen.has(s));
  assert.deepEqual(unreachable, [], `unreachable states: ${unreachable.join(', ')}`);
});

test('every non-terminal state can still move somewhere (no dead ends)', () => {
  for (const s of STATES.filter((x) => !wf.states[x].terminal)) {
    const out = wf.transitions.filter((t) => t.from.includes(s));
    assert.ok(out.length > 0, `dead end: ${s}`);
  }
});

test('every transition names a capability', () => {
  for (const t of wf.transitions) {
    assert.ok(typeof t.capability === 'string' && t.capability.startsWith('sire.'),
      `${t.action} must declare a sire.* capability`);
  }
});

test('non-transition actions are legal only in sensible states', () => {
  for (const a of wf.actions_without_transition) {
    for (const s of a.states) assert.ok(wf.states[s], `${a.action}: unknown state ${s}`);
    assert.ok(a.capability.startsWith('sire.'));
  }
  const accept = wf.actions_without_transition.find((a) => a.action === 'accept_assignment');
  assert.deepEqual(accept.states, ['assigned']);
  assert.equal(accept.guard, 'actor_is_assignee', 'only the assignee accepts their own assignment');
});

test('SLA pauses while waiting on release or a business decision, not while engineering owns it', () => {
  // business_review and approval pause because a change request sitting in a
  // governance queue is not engineering's clock to run down.
  assert.deepEqual(wf.sla_paused_states,
    ['on_hold', 'ready_for_release', 'released', 'business_review', 'approval']);
  for (const s of ['in_development', 'qa_in_progress', 'qa_failed']) {
    assert.ok(!wf.sla_paused_states.includes(s), `${s} is the team's own clock and must keep running`);
  }
});

// ---------------------------------------------------------------------------
// UI helpers — pure, so they are tested here rather than trusted.
// ---------------------------------------------------------------------------
const { decorateTransitions, railPosition, canTransition, transitionsFrom, stateLabel, isTerminal } =
  await import('../resources/js/lib/sire/workflow.js');

test('decorateTransitions orders primary first and destructive last', () => {
  const out = decorateTransitions([
    { action: 'reject', requires: ['resolution_note'] },
    { action: 'hold', requires: ['hold_reason'] },
    { action: 'start_development', requires: [] },
  ]);
  assert.deepEqual(out.map((t) => t.action), ['start_development', 'hold', 'reject']);
  assert.equal(out[0].primary, true);
  assert.equal(out[2].destructive, true);
});

test('decorateTransitions drops actions this client build does not know', () => {
  // Forward compatibility: an older SPA against a newer API must not crash.
  const out = decorateTransitions([{ action: 'teleport_to_mars' }, { action: 'close' }]);
  assert.deepEqual(out.map((t) => t.action), ['close']);
});

test('decorateTransitions carries the server-supplied requires through', () => {
  const [t] = decorateTransitions([{ action: 'release', requires: ['release_ref'] }]);
  assert.deepEqual(t.requires, ['release_ref']);
  assert.equal(t.to, 'released');
});

test('rail marks main-path states in place and side states as off-rail', () => {
  assert.deepEqual(railPosition('in_development'), { index: 3, offRail: false });
  const off = railPosition('qa_failed', 'qa_in_progress');
  assert.equal(off.offRail, true);
  assert.equal(off.index, 5, 'rests on the last main-path state actually reached');
  assert.deepEqual(railPosition('on_hold', null), { index: 0, offRail: true });
});

test('client mirror agrees with the JSON on legality', () => {
  assert.equal(canTransition('qa_in_progress', 'qa_fail'), true);
  assert.equal(canTransition('new', 'release'), false);
  assert.deepEqual(
    transitionsFrom('qa_in_progress').sort(),
    // close_directly is offered from every live state except production_validated,
    // which keeps the root-cause-guarded `close`. See docs/WORKFLOW.md.
    ['cannot_reproduce', 'close_directly', 'qa_fail', 'qa_pass'],
  );
});

test('labels and terminality are exposed for the UI', () => {
  assert.equal(stateLabel('in_development'), 'In Development');
  assert.equal(stateLabel('wont_fix'), "Won't Fix");
  assert.equal(isTerminal('closed'), true);
  assert.equal(isTerminal('qa_failed'), false);
  assert.equal(stateLabel('nonsense'), 'nonsense', 'unknown status must not blow up the header');
});

// ---------------------------------------------------------------------------
// Phase 2 — change-request track
// ---------------------------------------------------------------------------

const tracksOf = (action) => byAction.get(action).tracks ?? ['defect', 'change'];
const canOnTrack = (from, action, track) => can(from, action) && tracksOf(action).includes(track);

test('the change-request path the brief names is walkable end to end', () => {
  const path = [
    ['new', 'triage', 'triaged'],
    ['triaged', 'submit_business_review', 'business_review'],
    ['business_review', 'complete_business_review', 'impact_analysis'],
    ['impact_analysis', 'request_change_approval', 'approval'],
    ['approval', 'approve_change', 'planned'],
    ['planned', 'assign', 'assigned'],
    ['assigned', 'start_development', 'in_development'],
  ];
  for (const [from, action, to] of path) {
    assert.ok(canOnTrack(from, action, 'change'), `${from} --${action}--> should be legal on a change request`);
    assert.equal(byAction.get(action).to, to);
  }
});

test('a change request rejoins the defect pipeline and shares it from there', () => {
  // The whole point of one entity with two entry paths: after ASSIGNED there is
  // no such thing as a change-specific QA or release step.
  for (const action of ['mark_ready_for_qa', 'start_qa', 'qa_pass', 'qa_fail', 'release', 'close']) {
    assert.deepEqual(tracksOf(action), ['defect', 'change'], `${action} must serve both tracks`);
  }
});

test('a defect cannot enter the change-approval path', () => {
  assert.ok(!canOnTrack('triaged', 'submit_business_review', 'defect'),
    'a defect goes from triage to a developer, not to business review');
  for (const action of ['complete_business_review', 'request_change_approval', 'approve_change', 'reject_change']) {
    assert.deepEqual(tracksOf(action), ['change'], `${action} must be change-only`);
  }
});

test('a change request cannot skip approval', () => {
  assert.ok(!can('impact_analysis', 'assign'), 'planning requires an approval decision first');
  assert.ok(!can('business_review', 'assign'));
  assert.ok(!can('triaged', 'approve_change'));
  assert.ok(can('planned', 'assign'), 'approved changes are assignable');
});

test('approval is a two-way gate and raises a real approval record', () => {
  assert.ok(can('approval', 'approve_change'));
  assert.ok(can('approval', 'reject_change'));
  assert.equal(byAction.get('request_change_approval').raises_approval, 'change_request',
    'must raise a sire_approvals row rather than invent a sixth approval engine');
  assert.deepEqual(byAction.get('reject_change').requires, ['resolution_note']);
});

test('business review can send work back rather than only forward', () => {
  assert.ok(can('impact_analysis', 'return_to_business_review'));
  assert.ok(can('business_review', 'reject'));
  assert.ok(can('impact_analysis', 'reject'));
});

test('change states can be parked and resumed like any other', () => {
  const hold = byAction.get('hold');
  for (const s of ['business_review', 'impact_analysis', 'planned']) {
    assert.ok(hold.from.includes(s), `${s} must be holdable`);
  }
  assert.ok(!hold.from.includes('approval'),
    'approval already pauses the SLA; parking it too would double-count the wait');
});

test('every declared track is a real track', () => {
  for (const t of wf.transitions) {
    for (const track of t.tracks ?? []) {
      assert.ok(['defect', 'change'].includes(track), `${t.action}: unknown track ${track}`);
    }
  }
});
