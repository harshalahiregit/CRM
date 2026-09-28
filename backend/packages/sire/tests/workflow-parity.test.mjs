import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/**
 * The workflow is defined in PHP and exported to JSON for the SPA. Two copies of
 * one machine is a drift risk, so this test parses the PHP source and asserts the
 * checked-in JSON still matches it. This IS the CI drift check referenced in
 * workflow.generated.json — it fails the build rather than letting the UI label a
 * state the server no longer has.
 */
const json = (await import('../resources/js/lib/sire/workflow.generated.js')).default;

const statusSrc = readFileSync('src/Support/SireStatus.php', 'utf8');
const wfSrc = readFileSync('src/Support/SireWorkflow.php', 'utf8');

// ---- SireStatus::NAME -> 'value' -------------------------------------------
const CONST = {};
for (const m of statusSrc.matchAll(/public const ([A-Z_]+)\s*=\s*'([a-z_]+)';/g)) {
  CONST[m[1]] = m[2];
}
const deref = (token) => {
  const t = token.trim();
  const c = t.match(/^SireStatus::([A-Z_]+)$/);
  if (c) {
    assert.ok(CONST[c[1]], `SireStatus::${c[1]} is not defined`);
    return CONST[c[1]];
  }
  return t.replace(/^['"]|['"]$/g, '');
};

// ---- STATES ----------------------------------------------------------------
const statesBlock = wfSrc.slice(wfSrc.indexOf('const STATES = ['), wfSrc.indexOf('const TRANSITIONS'));
const phpStates = {};
for (const m of statesBlock.matchAll(
  /SireStatus::([A-Z_]+)\s*=>\s*\[\s*'label'\s*=>\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")\s*,\s*'group'\s*=>\s*'(\w+)'\s*,\s*'tone'\s*=>\s*'(\w+)'\s*,\s*'terminal'\s*=>\s*(true|false)\s*,\s*'order'\s*=>\s*(\d+)\s*\]/g,
)) {
  phpStates[CONST[m[1]]] = {
    label: (m[2] ?? m[3]).replace(/\\'/g, "'"),
    group: m[4],
    tone: m[5],
    terminal: m[6] === 'true',
    order: Number(m[7]),
  };
}

// ---- TRANSITIONS -----------------------------------------------------------
const trBlock = wfSrc.slice(wfSrc.indexOf('const TRANSITIONS = ['), wfSrc.indexOf('const ACTIONS'));
const phpTransitions = {};
for (const m of trBlock.matchAll(/^        '(\w+)' => \[([\s\S]*?)\n        \],/gm)) {
  const [, action, body] = m;
  const list = (key) => {
    const found = body.match(new RegExp(`'${key}'\\s*=>\\s*\\[([^\\]]*)\\]`));
    return found ? found[1].split(',').map((s) => s.trim()).filter(Boolean).map(deref) : undefined;
  };
  const scalar = (key) => {
    const found = body.match(new RegExp(`'${key}'\\s*=>\\s*(SireStatus::[A-Z_]+|'[^']*'|"[^"]*")`));
    return found ? deref(found[1]) : undefined;
  };
  phpTransitions[action] = {
    from: list('from'),
    to: scalar('to'),
    capability: scalar('capability'),
    requires: list('requires'),
    guard: scalar('guard'),
    auto_advance: scalar('auto_advance'),
    notifies: scalar('notifies'),
    tracks: list('tracks'),
    raises_approval: scalar('raises_approval'),
    clears: list('clears'),
  };
}

// ---- assertions ------------------------------------------------------------
test('the PHP source parsed at all (guards against a silently empty test)', () => {
  assert.ok(Object.keys(CONST).length >= 18, 'status constants');
  assert.equal(Object.keys(phpStates).length, 22, 'parsed states');
  assert.equal(Object.keys(phpTransitions).length, 27, 'parsed transitions');
});

test('state sets match exactly', () => {
  assert.deepEqual(Object.keys(phpStates).sort(), Object.keys(json.states).sort());
});

test('every state label, group, tone, terminal flag and order matches', () => {
  for (const [name, php] of Object.entries(phpStates)) {
    assert.deepEqual(json.states[name], php, `state "${name}" differs between PHP and JSON`);
  }
});

test('transition sets match exactly', () => {
  const jsonActions = json.transitions.map((t) => t.action).sort();
  assert.deepEqual(Object.keys(phpTransitions).sort(), jsonActions);
});

test('every transition from/to/capability/requires/guard matches', () => {
  for (const jsonT of json.transitions) {
    const php = phpTransitions[jsonT.action];
    assert.ok(php, `${jsonT.action} missing from PHP`);
    assert.deepEqual(php.from, jsonT.from, `${jsonT.action}.from`);
    assert.equal(php.to, jsonT.to, `${jsonT.action}.to`);
    assert.equal(php.capability, jsonT.capability, `${jsonT.action}.capability`);
    assert.deepEqual(php.requires ?? [], jsonT.requires ?? [], `${jsonT.action}.requires`);
    assert.equal(php.guard ?? null, jsonT.guard ?? null, `${jsonT.action}.guard`);
    assert.equal(php.auto_advance ?? null, jsonT.auto_advance ?? null, `${jsonT.action}.auto_advance`);
    assert.equal(php.notifies ?? null, jsonT.notifies ?? null, `${jsonT.action}.notifies`);
    // A track mismatch would silently offer a change-only action on a defect.
    assert.deepEqual(php.tracks ?? null, jsonT.tracks ?? null, `${jsonT.action}.tracks`);
    assert.equal(php.raises_approval ?? null, jsonT.raises_approval ?? null, `${jsonT.action}.raises_approval`);
    // Regression: `clears` was in PHP but not the mirror, and nothing noticed
    // because the comparison did not look at it.
    assert.deepEqual(php.clears ?? [], jsonT.clears ?? [], `${jsonT.action}.clears`);
  }
});

test('SLA-paused states match SireStatus::SLA_PAUSED', () => {
  // The list is multi-line and carries explanatory comments. Splitting the raw
  // text produced `undefined` entries that still compared "close enough" to the
  // eye — strip comments first so this compares constants, not prose.
  const clean = statusSrc.replace(/\/\/[^\n]*/g, '');
  const block = clean.match(/const SLA_PAUSED = \[([^\]]*)\]/)[1];
  const php = block.split(',').map((x) => x.trim()).filter(Boolean).map((x) => CONST[x.replace('self::', '')]);
  assert.ok(php.every(Boolean), `unresolved constant in SLA_PAUSED: ${JSON.stringify(php)}`);
  assert.deepEqual(php, json.sla_paused_states);
});

test('terminal states match SireStatus::TERMINAL', () => {
  const block = statusSrc.match(/const TERMINAL = \[([\s\S]*?)\];/)[1];
  const php = block.split(',').map((s) => s.trim()).filter(Boolean).map((s) => CONST[s.replace('self::', '')]).sort();
  const fromJson = Object.entries(json.states).filter(([, v]) => v.terminal).map(([k]) => k).sort();
  assert.deepEqual(php, fromJson);
});

test('non-transition actions match', () => {
  const actBlock = wfSrc.slice(wfSrc.indexOf('const ACTIONS = ['));
  const phpActions = [...actBlock.matchAll(/'(\w+)'\s*=> \['states'/g)].map((m) => m[1]).sort();
  assert.deepEqual(phpActions, json.actions_without_transition.map((a) => a.action).sort());
});

test('no JSON-only keys: the exporter must be able to reproduce this file', () => {
  // Regression: the checked-in JSON carried `note` fields the PHP did not, so
  // `sire:export-workflow --check` would have failed on its very first run.
  const trBlock = wfSrc.slice(wfSrc.indexOf('const TRANSITIONS = ['), wfSrc.indexOf('const ACTIONS'));
  for (const jsonT of json.transitions) {
    const body = trBlock.match(new RegExp(`^        '${jsonT.action}' => \\[([\\s\\S]*?)\\n        \\],`, 'm'))[1];
    for (const key of Object.keys(jsonT)) {
      if (key === 'action') continue;
      assert.ok(body.includes(`'${key}'`), `transitions.${jsonT.action}.${key} exists in JSON but not in PHP`);
    }
  }
  const actBlock = wfSrc.slice(wfSrc.indexOf('const ACTIONS = ['));
  for (const a of json.actions_without_transition) {
    // Whole line: the inner `'states' => [...]` array truncates a [^\]]* match.
    const body = actBlock.match(new RegExp(`^\\s*'${a.action}'\\s*=> \\[(.*)\\],\\s*$`, 'm'))[1];
    for (const key of Object.keys(a)) {
      if (key === 'action') continue;
      assert.ok(body.includes(`'${key}'`), `actions.${a.action}.${key} exists in JSON but not in PHP`);
    }
  }
});
