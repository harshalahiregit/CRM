import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/**
 * The release lifecycle is verified by PARSING THE PHP, not by comparing against
 * a JS mirror.
 *
 * The issue workflow has a mirror because the SPA needs its labels and colours
 * without a round-trip. The release lifecycle is small and the SPA only ever
 * renders what the API sends, so a third generated artefact would be a drift risk
 * bought for nothing. Parsing the source keeps one source of truth.
 */
const src = readFileSync('src/Support/SireReleaseStatus.php', 'utf8');

const CONST = {};
for (const m of src.matchAll(/public const ([A-Z_]+)\s*=\s*'([a-z_]+)';/g)) CONST[m[1]] = m[2];

const deref = (token) => {
  const t = token.trim().replace(/^self::/, '');
  return CONST[t] ?? t.replace(/^['"]|['"]$/g, '');
};

const constList = (name) => {
  const block = src.replace(/\/\/[^\n]*/g, '').match(new RegExp(`const ${name} = \\[([\\s\\S]*?)\\];`))[1];
  return block.split(',').map((s) => s.trim()).filter(Boolean).map(deref);
};

const trBlock = src.slice(src.indexOf('const TRANSITIONS = ['), src.indexOf('public static function label'));
const TRANSITIONS = {};
for (const m of trBlock.matchAll(/^        '(\w+)' => \[([\s\S]*?)\n        \],/gm)) {
  const [, action, body] = m;
  const from = body.match(/'from'\s*=>\s*\[([^\]]*)\]/)[1].split(',').map((s) => s.trim()).filter(Boolean).map(deref);
  const to = deref(body.match(/'to'\s*=>\s*(self::[A-Z_]+)/)[1]);
  const capability = body.match(/'capability'\s*=>\s*'([^']+)'/)?.[1] ?? null;
  const requires = body.match(/'requires'\s*=>\s*\[([^\]]*)\]/)?.[1]
    ?.split(',').map((s) => s.trim().replace(/'/g, '')).filter(Boolean) ?? [];
  TRANSITIONS[action] = { from, to, capability, requires };
}

const STATES = constList('ALL');
const can = (from, action) => Boolean(TRANSITIONS[action]?.from.includes(from));

test('the PHP parsed (guards against a silently empty test)', () => {
  assert.ok(STATES.length >= 5, `parsed states: ${STATES}`);
  assert.equal(Object.keys(TRANSITIONS).length, 5, 'parsed transitions');
});

test('the five states the brief names all exist', () => {
  for (const s of ['ready', 'blocked', 'approved', 'released', 'cancelled']) {
    assert.ok(STATES.includes(s), `missing state: ${s}`);
  }
});

test('rolled_back is the only state beyond the brief, and it is deliberate', () => {
  const extra = STATES.filter((s) => !['ready', 'blocked', 'approved', 'released', 'cancelled'].includes(s));
  assert.deepEqual(extra, ['rolled_back']);
  // Cancelled means it never shipped; rolled back means it shipped and was
  // withdrawn. Collapsing them loses the only fact that matters when someone asks
  // whether customers ever had it.
  assert.ok(src.includes('never shipped'), 'the distinction must be documented in the source');
});

test('NOTHING transitions to ready or blocked — those are derived from the gates', () => {
  for (const [action, t] of Object.entries(TRANSITIONS)) {
    if (action === 'revoke_approval') continue; // returns to the derived pair, then recomputed
    assert.ok(!['ready', 'blocked'].includes(t.to),
      `${action} targets ${t.to}; a button that declares a release ready without making it so`);
  }
});

test('a blocked release cannot be approved or released', () => {
  assert.ok(!can('blocked', 'approve'), 'gates must pass or an override must cover them');
  assert.ok(!can('blocked', 'release'));
  assert.ok(!can('ready', 'release'), 'approval is not optional');
});

test('the governed path is approve then release, in that order', () => {
  assert.ok(can('ready', 'approve'));
  assert.equal(TRANSITIONS.approve.to, 'approved');
  assert.ok(can('approved', 'release'));
  assert.equal(TRANSITIONS.release.to, 'released');
});

test('approval can be revoked before shipping, but not after', () => {
  assert.ok(can('approved', 'revoke_approval'));
  assert.ok(!can('released', 'revoke_approval'));
});

test('cancelling is possible until it ships, and never after', () => {
  for (const s of ['blocked', 'ready', 'approved']) assert.ok(can(s, 'cancel'), `cancel from ${s}`);
  assert.ok(!can('released', 'cancel'), 'a shipped release is rolled back, not cancelled');
  assert.deepEqual(TRANSITIONS.cancel.requires, ['reason']);
});

test('rollback applies only to a released version and records why', () => {
  assert.ok(can('released', 'roll_back'));
  assert.ok(!can('ready', 'roll_back'));
  assert.deepEqual(TRANSITIONS.roll_back.requires, ['reason']);
});

test('terminal states are dead ends', () => {
  for (const s of constList('TERMINAL')) {
    const out = Object.entries(TRANSITIONS).filter(([, t]) => t.from.includes(s));
    assert.deepEqual(out, [], `${s} should have no outbound transitions`);
  }
});

test('every state is reachable from the gate-derived pair a release lives in', () => {
  // BOTH blocked and ready are entry points: a release oscillates between them as
  // its gates change, and neither is reached by a transition. Seeding only
  // blocked made this fail — correctly, since approve starts from ready.
  const seen = new Set(constList('GATE_DERIVED'));
  assert.deepEqual([...seen].sort(), ['blocked', 'ready'], 'the derived pair is the entry point');

  let changed = true;
  while (changed) {
    changed = false;
    for (const t of Object.values(TRANSITIONS)) {
      if (t.from.some((f) => seen.has(f)) && !seen.has(t.to)) { seen.add(t.to); changed = true; }
    }
  }

  const unreachable = STATES.filter((s) => !seen.has(s));
  assert.deepEqual(unreachable, [], `unreachable: ${unreachable.join(', ')}`);
});

test('a release with failing gates can only be cancelled', () => {
  const fromBlocked = Object.entries(TRANSITIONS)
    .filter(([, t]) => t.from.includes('blocked'))
    .map(([action]) => action);
  assert.deepEqual(fromBlocked, ['cancel'],
    'the only way out of blocked is to fix the gates, override them, or give up');
});

test('every transition declares a sire.release.* capability', () => {
  for (const [action, t] of Object.entries(TRANSITIONS)) {
    assert.ok(t.capability?.startsWith('sire.release.'), `${action} has no capability`);
  }
  // Approving and shipping are different authorities on purpose.
  assert.equal(TRANSITIONS.approve.capability, 'sire.release.approve');
  assert.equal(TRANSITIONS.release.capability, 'sire.release.manage');
});
