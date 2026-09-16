import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { redact, scrubValue, MAX_VALUE_LENGTH, MAX_FIELDS } from './reference/aiRedaction.mjs';

const spec = JSON.parse(readFileSync('tests/fixtures/ai-redaction-cases.json', 'utf8'));

/** Read the PHP allowlist so both sides are driven by the same source. */
const SCHEMA_SRC = readFileSync('src/Support/Ai/AiContextSchema.php', 'utf8');
const ALLOWLIST = {};
{
  const block = SCHEMA_SRC.slice(SCHEMA_SRC.indexOf('const FIELDS = ['), SCHEMA_SRC.indexOf('FORBIDDEN_KEY_PATTERN'));
  for (const m of block.matchAll(/AiCapability::([A-Z_]+)\s*=>\s*\[([\s\S]*?)\],\s*\n/g)) {
    const key = m[1].toLowerCase();
    ALLOWLIST[key] = [...m[2].matchAll(/'([a-z_]+)'/g)].map((x) => x[1]);
  }
}

const expand = (v) => {
  if (v === 'MANY:60') return Array.from({ length: 60 }, (_, i) => `f${i}`);
  // Arrays pass through untouched: typeof [] === 'object', so without this an
  // allowlist override came back as {0:'title',1:...} and stopped being iterable.
  if (Array.isArray(v)) return v;
  if (typeof v === 'object' && v !== null) {
    const out = {};
    for (const [k, val] of Object.entries(v)) {
      out[k] = typeof val === 'string' && val.startsWith('LONG:')
        ? 'x'.repeat(Number(val.split(':')[1]))
        : val;
    }
    return out;
  }
  return v;
};

test('the allowlist was parsed out of the PHP (guards against a vacuous check)', () => {
  assert.ok(Object.keys(ALLOWLIST).length >= 12, `parsed capabilities: ${Object.keys(ALLOWLIST).length}`);
  assert.ok(ALLOWLIST.classification.includes('title'));
});

for (const c of spec.cases) {
  test(`redaction: ${c.name}`, () => {
    let input = expand(c.input);
    let allowlist = c.allowlist_override
      ? expand(c.allowlist_override)
      : (ALLOWLIST[c.capability] ?? []);

    if (c.input === 'MANY:60') {
      input = Object.fromEntries(expand('MANY:60').map((f) => [f, 'value']));
    }

    const { context, report } = redact(c.capability, input, allowlist);
    const e = c.expect;

    if (e.kept) assert.deepEqual(report.kept.sort(), [...e.kept].sort(), 'kept');
    if (e.dropped) for (const f of e.dropped) assert.ok(f in report.dropped, `${f} should be dropped`);
    if (e.drop_reason) for (const [f, r] of Object.entries(e.drop_reason)) assert.equal(report.dropped[f], r, `${f} reason`);
    if (e.empty) assert.deepEqual(context, {});
    if (e.max_fields) assert.ok(report.kept.length <= e.max_fields, `field cap: ${report.kept.length}`);
    if (e.truncated) for (const f of e.truncated) assert.ok(report.truncated.includes(f), `${f} truncated`);
    if (e.max_value_length) {
      for (const v of Object.values(context)) {
        if (typeof v === 'string') assert.ok(v.length <= e.max_value_length);
      }
    }
    if (e.types) for (const [f, t] of Object.entries(e.types)) assert.equal(typeof context[f], t, `${f} type`);
    if (e.structured_ok) assert.ok(Array.isArray(context.top_modules) || typeof context.sections === 'object');

    const serialised = JSON.stringify(context);
    for (const s of e.value_contains_not ?? []) assert.ok(!serialised.includes(s), `leaked: ${s}`);
    for (const s of e.value_contains ?? []) assert.ok(serialised.includes(s), `missing: ${s}`);
  });
}

test('nothing reaches a provider for a capability with no allowlist', () => {
  // Failing closed matters most exactly where someone has made a mistake.
  const { context } = redact('typo_capability', { title: 'x', description: 'y' }, ALLOWLIST.typo_capability);
  assert.deepEqual(context, {});
});

test('every declared capability has an allowlist, and none of them allows a secret', () => {
  const forbidden = /(password|token|secret|auth|cookie|session|credential|apikey|signature|bearer|jwt)/i;
  const pii = /(email|phone|mobile|address|full_name|first_name|last_name|username)/i;

  for (const [capability, fields] of Object.entries(ALLOWLIST)) {
    assert.ok(fields.length > 0, `${capability} has an empty allowlist`);
    for (const f of fields) {
      assert.ok(!forbidden.test(f), `${capability} allowlists a secret-shaped field: ${f}`);
      assert.ok(!pii.test(f), `${capability} allowlists a person-identifying field: ${f}`);
    }
  }
});

test('no allowlist anywhere admits an identity column', () => {
  for (const [capability, fields] of Object.entries(ALLOWLIST)) {
    for (const f of fields) {
      assert.ok(!/^(tenant_id|reporter_id|assignee_id|user_id|owner_id|created_by)$/.test(f),
        `${capability} allowlists identity: ${f}`);
    }
  }
});

test('scrubValue leaves ordinary prose alone', () => {
  const prose = 'Saving a lead returns a 500 after changing the owner on the details tab.';
  assert.equal(scrubValue(prose), prose, 'over-redaction makes the context useless');
});

test('scrubValue removes credentials from prose', () => {
  const dirty = 'Login as ops@example.com with token Ab9xQ2Lm7Nb4Vc8Rt1Yu6Wq3Ee5Rr then retry';
  const clean = scrubValue(dirty);
  assert.ok(!clean.includes('ops@example.com'));
  assert.ok(!clean.includes('Ab9xQ2Lm7Nb4Vc8Rt1Yu6Wq3Ee5Rr'));
  assert.ok(clean.includes('Login as'), 'the sentence should still read');
});

test('a structured field cannot smuggle a nested secret', () => {
  const { context } = redact('engineering_insights', {
    top_modules: [{ module: 'sales', api_key: 'sk-live-abcdefghijklmnop', owner_email: 'a@b.com' }],
  }, ['top_modules']);

  const serialised = JSON.stringify(context);
  assert.ok(!serialised.includes('sk-live'));
  assert.ok(!serialised.includes('a@b.com'));
  assert.ok(serialised.includes('sales'), 'the useful part survives');
});

test('the redaction report accounts for everything that was passed in', () => {
  // A field must be either kept or explained. Silent disappearance is how nobody
  // notices that a capability has been sending less than it should for a year.
  const input = { title: 'x', description: 'y', secret_token: 'z', assignee_id: 4 };
  const { report } = redact('classification', input, ALLOWLIST.classification);

  for (const field of Object.keys(input)) {
    assert.ok(report.kept.includes(field) || field in report.dropped, `${field} unaccounted for`);
  }
});
