import { test } from 'node:test';
import assert from 'node:assert/strict';
import { redactUrl, redactPath, redactQueryString } from '../resources/js/lib/sire/redact.js';

test('ordinary CRM url passes through unchanged', () => {
  assert.equal(
    redactUrl('https://app.sangoe.in/app/sales/leads/10452?tab=notes&page=2'),
    'https://app.sangoe.in/app/sales/leads/10452?tab=notes&page=2',
  );
});

test('token-named query params are redacted', () => {
  const out = redactUrl('https://app.sangoe.in/app/x?token=abc123&access_token=z&api_key=k&page=2');
  assert.match(out, /token=\[redacted\]/);
  assert.match(out, /access_token=\[redacted\]/);
  assert.match(out, /api_key=\[redacted\]/);
  assert.match(out, /page=2/);
  assert.ok(!out.includes('abc123'));
});

test('long opaque query values are redacted even under an innocent name', () => {
  const out = redactQueryString('?ref=Zx9QpLm2Nb7Vc4Rt8Yu1Wq3Ee5Rr&page=1');
  assert.match(out, /ref=\[redacted\]/);
  assert.match(out, /page=1/);
});

test("public ticket '{id}-{email_token}' path shape keeps the id, drops the token", () => {
  assert.equal(
    redactPath('/helpdesk/public/ticket/4471-Ab9xQ2Lm7Nb4Vc8Rt1Yu6Wq'),
    '/helpdesk/public/ticket/4471-:token',
  );
});

test('opaque path segments (portal / widget keys) are redacted', () => {
  assert.equal(
    redactPath('/helpdesk/public/widget/wk_9f2b71c4a8e34d15b0c7'),
    '/helpdesk/public/widget/:token',
  );
});

test('numeric ids are never mistaken for tokens', () => {
  assert.equal(redactPath('/app/sales/leads/10452'), '/app/sales/leads/10452');
});

test('fragment is dropped', () => {
  assert.ok(!redactUrl('https://app.sangoe.in/app/x#secret-anchor').includes('#'));
});

test('malformed input does not throw', () => {
  assert.doesNotThrow(() => redactUrl('::::not a url::::'));
  assert.equal(redactUrl(null), null);
});

test('human slugs are NOT mistaken for secrets (digit-density guard)', () => {
  assert.equal(redactPath('/app/kb/annual-safety-review-2026'), '/app/kb/annual-safety-review-2026');
  assert.equal(redactPath('/app/compliance/checklists/fire-extinguisher-monthly'),
    '/app/compliance/checklists/fire-extinguisher-monthly');
});

test('long numeric ids are not secrets', () => {
  assert.equal(redactPath('/app/accounts/vouchers/20260824000123'), '/app/accounts/vouchers/20260824000123');
});

test('a 16-char mixed token is caught (regression: 24-char threshold was too lax)', () => {
  assert.equal(redactPath('/x/a1b2c3d4e5f6g7h8'), '/x/:token');
});
