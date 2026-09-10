import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SireContextCollector } from '../resources/js/lib/sire/collector.js';
import { recordFailedRequest, clearFailedRequests } from '../resources/js/lib/sire/requestLog.js';

const collect = (opts) => SireContextCollector.collect(opts);

test('route detection alone fills module / section / screen / entity', () => {
  const c = collect({ pathname: '/app/sales/leads/10452', href: 'https://app.sangoe.in/app/sales/leads/10452' });
  assert.equal(c.module, 'sales');
  assert.equal(c.section, 'leads');
  assert.equal(c.screen, 'lead-details');
  assert.equal(c.entity_type, 'lead');
  assert.equal(c.entity_id, '10452');
  assert.equal(c.context_source, 'route');
  assert.equal(c.context_confidence, 'high');
  assert.equal(c.labels.module, 'Sales');
  assert.equal(c.labels.entity, 'Lead #10452');
});

test('the exact display the brief asks for', () => {
  const { labels } = collect({ pathname: '/app/sales/leads/10452' });
  assert.deepEqual(
    { Module: labels.module, Section: labels.section, Screen: labels.screen, Record: labels.entity },
    { Module: 'Sales', Section: 'Leads', Screen: 'Lead Details', Record: 'Lead #10452' },
  );
});

test('explicit <SireContext> beats the route map', () => {
  const c = collect({
    pathname: '/app/sales/some-unmapped-wizard',
    explicit: { module: 'sales', section: 'leads', screen: 'lead-import-wizard', entityType: 'lead', entityId: '99' },
  });
  assert.equal(c.screen, 'lead-import-wizard');
  assert.equal(c.entity_id, '99');
  assert.equal(c.context_source, 'provider');
  assert.equal(c.context_confidence, 'high');
  assert.equal(c.entity_source, 'provider');
});

test('user correction beats everything and is marked as such', () => {
  const c = collect({
    pathname: '/app/sales/leads/10452',
    explicit: { module: 'sales', section: 'leads', screen: 'lead-details' },
    overrides: { module: 'customer', section: 'clients', screen: 'client-details' },
  });
  assert.equal(c.module, 'customer');
  assert.equal(c.context_source, 'user');
  assert.equal(c.context_confidence, 'high');
});

test('unmapped route still identifies the module, flagged for correction', () => {
  const c = collect({ pathname: '/app/inventory/brand-new-screen' });
  assert.equal(c.module, 'inventory');
  assert.equal(c.screen, null);
  assert.equal(c.context_confidence, 'low');   // -> the modal shows "Not right? Correct it"
  assert.equal(c.context_source, 'module-prefix');
});

test('the client never sends tenant or user identity', () => {
  const c = collect({ pathname: '/app/sales/leads/10452' });
  for (const forbidden of ['tenant_id', 'user_id', 'reporter_id', 'tenant', 'user']) {
    assert.equal(c[forbidden], undefined, `context must not carry ${forbidden}`);
  }
});

test('no forbidden material can enter via page_context', () => {
  const c = collect({
    pathname: '/app/sales/leads/10452',
    explicit: {
      module: 'sales',
      pageContext: {
        tab: 'notes',
        filterCount: 3,
        password: 'hunter2',
        access_token: 'abc',
        Authorization: 'Bearer x',
        sessionCookie: 'sid=1',
        apiKey: 'k',
        nested: { a: 1 },
        huge: 'x'.repeat(500),
      },
    },
  });
  assert.deepEqual(c.page_context, { tab: 'notes', filterCount: '3' });
  const serialised = JSON.stringify(c);
  for (const leak of ['hunter2', 'Bearer', 'sid=1']) {
    assert.ok(!serialised.includes(leak), `leaked: ${leak}`);
  }
});

test('url is redacted before it leaves the browser', () => {
  const c = collect({
    pathname: '/app/helpdesk/tickets/8821',
    href: 'https://app.sangoe.in/app/helpdesk/tickets/8821?token=Ab9xQ2Lm7Nb4Vc8Rt1Yu6Wq&tab=replies#x',
  });
  assert.ok(!c.url.includes('Ab9xQ2Lm7Nb4Vc8Rt1Yu6Wq'));
  assert.ok(c.url.includes('tab=replies'));
  assert.ok(!c.url.includes('#'));
});

test('failed request context: metadata only, newest first, no bodies or headers', () => {
  clearFailedRequests();
  recordFailedRequest({
    config: { method: 'patch', url: 'https://app.sangoe.in/api/sales/leads/10452?token=secret', data: { password: 'hunter2' } },
    response: { status: 422, headers: { 'x-request-id': 'req-abc-123' }, data: { reference: 'A1B2C3', message: 'boom' } },
  });
  recordFailedRequest({ config: { method: 'get', url: '/api/sales/leads' }, response: { status: 500 } });

  const c = collect({ pathname: '/app/sales/leads/10452' });
  assert.equal(c.failed_requests.length, 2);

  const [newest, older] = c.failed_requests;
  assert.equal(newest.status, 500);
  assert.equal(older.method, 'PATCH');
  assert.equal(older.path, '/api/sales/leads/10452');   // query dropped entirely
  assert.equal(older.correlation_ref, 'req-abc-123');
  assert.deepEqual(Object.keys(older).sort(), ['correlation_ref', 'method', 'occurred_at', 'path', 'status']);

  const serialised = JSON.stringify(c);
  assert.ok(!serialised.includes('hunter2'));
  assert.ok(!serialised.includes('secret'));
});

test('failed request buffer is bounded and never throws on junk', () => {
  clearFailedRequests();
  for (let i = 0; i < 25; i += 1) {
    recordFailedRequest({ config: { method: 'get', url: `/api/x/${i}` }, response: { status: 500 } });
  }
  assert.doesNotThrow(() => recordFailedRequest(null));
  assert.doesNotThrow(() => recordFailedRequest({}));
  const c = collect({ pathname: '/app/sales/leads' });
  assert.ok(c.failed_requests.length <= 3, 'at most 3 attached to a report');
});

test('network error (no response) records status 0', () => {
  clearFailedRequests();
  recordFailedRequest({ config: { method: 'post', url: '/api/sire/reports' } });
  const c = collect({ pathname: '/app/sire' });
  assert.equal(c.failed_requests[0].status, 0);
});

/**
 * Performance is guaranteed STRUCTURALLY, not by a stopwatch.
 *
 * An earlier version of this test asserted a wall-clock budget of 1ms per call.
 * It passed at 119µs on an idle machine and failed at 4.5ms on a loaded one —
 * same code, load average 690 on 4 cores. A test that fails because an unrelated
 * process is busy teaches people to ignore failures, so the timing assertion is
 * gone. What replaces it is the property the budget was a proxy for: collect()
 * does no DOM traversal on the happy path, and touches the DOM at most once ever.
 */
test('collect() does not touch the DOM when the route already resolved the entity', () => {
  let queries = 0;
  const original = globalThis.document;
  globalThis.document = { querySelector: () => { queries += 1; return null; } };
  try {
    collect({ pathname: '/app/sales/leads/10452' });
    assert.equal(queries, 0, 'route match resolved the entity; the DOM must not be consulted');

    collect({ pathname: '/app/sales/leads', explicit: { module: 'sales', entityType: 'lead', entityId: '7' } });
    assert.equal(queries, 0, 'explicit provider resolved the entity; still no DOM');

    collect({ pathname: '/app/inventory/unmapped-screen' });
    assert.equal(queries, 1, 'only an unresolved entity falls back to a single querySelector');
  } finally {
    globalThis.document = original;
  }
});

test('collect() runs a bounded amount of work regardless of input', () => {
  // Catastrophic-breakage guard only (an infinite loop, an accidental O(n²) over
  // the route table). Deliberately 100x looser than the real figure so machine
  // load cannot make it flake.
  clearFailedRequests();
  const start = process.hrtime.bigint();
  for (let i = 0; i < 200; i += 1) collect({ pathname: '/app/purchase/orders/PO-2026-014' });
  const msPerCall = Number(process.hrtime.bigint() - start) / 1e6 / 200;
  assert.ok(msPerCall < 50, `collect() averaged ${msPerCall.toFixed(2)}ms — something is pathologically wrong`);
});

test('a correction relabels — the label follows the corrected value, not the route', () => {
  const c = collect({
    pathname: '/app/sales/leads/10452',
    overrides: { module: 'customer', section: 'clients', screen: 'client-details' },
  });
  assert.equal(c.labels.module, 'Customer 360', 'label must follow the correction');
  assert.equal(c.labels.section, 'Clients');
  assert.equal(c.labels.screen, 'Client Details');
});

test('a custom route label survives when the value is not corrected', () => {
  const c = collect({ pathname: '/app/purchase/orders/PO-2026-014' });
  assert.equal(c.labels.screen, 'Purchase Order Details');
});

test('correcting only the module leaves the untouched labels alone', () => {
  const c = collect({ pathname: '/app/sales/leads/10452', overrides: { module: 'helpdesk' } });
  assert.equal(c.labels.module, 'Helpdesk');
  assert.equal(c.labels.section, 'Leads');
});

test('acronym entity types get a readable label', () => {
  const c = collect({ pathname: '/app/tpv/incidents/12' });
  assert.equal(c.labels.section, 'HSSE');
  assert.equal(c.labels.entity, 'HSSE Incident #12');
});
