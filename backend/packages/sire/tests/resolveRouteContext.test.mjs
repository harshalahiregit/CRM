import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolveRouteContext, titleize } from '../resources/js/lib/sire/resolveRouteContext.js';

/**
 * VERIFY step 1 — route detection across several different CRM modules.
 * Each case asserts module / section / screen / entity as a user would see them.
 */
const CASES = [
  {
    path: '/app/sales/leads/10452',
    expect: { module: 'sales', section: 'leads', screen: 'lead-details', entityType: 'lead', entityId: '10452', confidence: 'high' },
    display: { moduleLabel: 'Sales', sectionLabel: 'Leads', screenLabel: 'Lead Details', entityLabel: 'Lead #10452' },
  },
  {
    path: '/app/sales/leads',
    expect: { module: 'sales', section: 'leads', screen: 'lead-list', entityType: null, entityId: null, confidence: 'high' },
    display: { screenLabel: 'Lead List', entityLabel: null },
  },
  {
    path: '/app/helpdesk/tickets/8821',
    expect: { module: 'helpdesk', section: 'tickets', screen: 'ticket-details', entityType: 'ticket', entityId: '8821', confidence: 'high' },
    display: { moduleLabel: 'Helpdesk', entityLabel: 'Ticket #8821' },
  },
  {
    path: '/app/hr/candidates/77',
    expect: { module: 'hr', section: 'recruitment', screen: 'candidate-details', entityType: 'candidate', entityId: '77', confidence: 'high' },
    display: { moduleLabel: 'HR & Recruitment', sectionLabel: 'Recruitment', entityLabel: 'Candidate #77' },
  },
  {
    path: '/app/purchase/orders/PO-2026-014',
    expect: { module: 'purchase', section: 'orders', screen: 'po-details', entityType: 'purchase_order', entityId: 'PO-2026-014', confidence: 'high' },
    display: { screenLabel: 'Purchase Order Details', entityLabel: 'Purchase Order #PO-2026-014' },
  },
  {
    path: '/app/tpv/incidents/12',
    expect: { module: 'tpv', section: 'hsse', screen: 'incident-details', entityType: 'hsse_incident', entityId: '12', confidence: 'high' },
    display: { moduleLabel: 'TPV / HSSE', sectionLabel: 'HSSE', entityLabel: 'Hsse Incident #12' },
  },
  {
    path: '/app/accounts/vouchers/9001',
    expect: { module: 'accounts', section: 'vouchers', screen: 'voucher-details', entityType: 'voucher', entityId: '9001', confidence: 'high' },
  },
  {
    path: '/app/inventory/products/55',
    expect: { module: 'inventory', section: 'products', screen: 'product-details', entityType: 'product', entityId: '55', confidence: 'high' },
  },
  {
    path: '/app/customer/clients/301/timeline',
    expect: { module: 'customer', section: 'clients', screen: 'client-timeline', entityType: 'client', entityId: '301', confidence: 'high' },
    display: { moduleLabel: 'Customer 360', screenLabel: 'Client Timeline' },
  },
  {
    path: '/app/projects/44/milestones',
    expect: { module: 'projects', section: 'milestones', screen: 'milestone-list', entityType: 'project', entityId: '44', confidence: 'high' },
  },
  {
    path: '/app/projects/44/tasks/912',
    expect: { module: 'projects', section: 'project-tasks', screen: 'project-task-details', entityType: 'task', entityId: '912', confidence: 'high' },
  },
  {
    path: '/app/tasks/912',
    expect: { module: 'tasks', section: 'tasks', screen: 'task-details', entityType: 'task', entityId: '912', confidence: 'high' },
  },
  {
    path: '/app/sire/cases/7',
    expect: { module: 'sire', section: 'register', screen: 'case-details', entityType: 'sire_report', entityId: '7', confidence: 'high' },
  },
];

for (const c of CASES) {
  test(`route: ${c.path}`, () => {
    const r = resolveRouteContext(c.path);
    for (const [k, v] of Object.entries(c.expect)) {
      assert.equal(r[k], v, `${c.path} -> ${k}`);
    }
    for (const [k, v] of Object.entries(c.display || {})) {
      assert.equal(r[k], v, `${c.path} -> ${k}`);
    }
    assert.equal(r.source, 'route');
  });
}

test('specificity: deeper + more static segments wins regardless of map order', () => {
  assert.equal(resolveRouteContext('/app/sales/leads/10452/edit').screen, 'lead-edit');
  assert.equal(resolveRouteContext('/app/sales/leads/10452').screen, 'lead-details');
});

test('wildcard pattern matches any depth below it', () => {
  const a = resolveRouteContext('/app/settings/mail');
  const b = resolveRouteContext('/app/settings/mail/templates/3');
  assert.equal(a.module, 'settings');
  assert.equal(b.module, 'settings');
  assert.equal(b.source, 'route');
});

test('unmapped route under a mapped parent inherits it, still flagged for correction', () => {
  // This used to assert module-only detection, because the package copy of the
  // resolver had never received the prefix pass the host had been running for
  // months. Inheriting the parent is the better answer: an unmapped screen
  // under '/app/sales' is still a Sales screen.
  const r = resolveRouteContext('/app/sales/some-brand-new-screen');
  assert.equal(r.module, 'sales');
  assert.equal(r.moduleLabel, 'Sales');
  assert.equal(r.section, 'overview');
  assert.equal(r.screen, 'overview-some-brand-new-screen');
  // Derived, not mapped -- so the modal still offers "Not right? Correct it".
  assert.equal(r.confidence, 'medium');
  assert.equal(r.source, 'route-prefix');
});

test('a known module with no mapped parent still degrades to module-prefix', () => {
  // The last line of defence before giving up entirely: the module name is in
  // the URL, so it is still worth reporting even with nothing else known.
  const r = resolveRouteContext('/app/shared/some-brand-new-screen');
  assert.equal(r.module, 'shared');
  assert.equal(r.section, null);
  assert.equal(r.screen, null);
  assert.equal(r.confidence, 'low');
  assert.equal(r.source, 'module-prefix');
});

test('completely unknown route never throws and reports low confidence', () => {
  const r = resolveRouteContext('/app/does-not-exist/1/2/3');
  assert.equal(r.module, null);
  assert.equal(r.confidence, 'low');
  assert.equal(r.source, 'none');
});

test('trailing slash, query string and hash do not affect matching', () => {
  const base = resolveRouteContext('/app/sales/leads/10452');
  for (const variant of [
    '/app/sales/leads/10452/',
    '/app/sales/leads/10452?tab=notes',
    '/app/sales/leads/10452#activity',
  ]) {
    const r = resolveRouteContext(variant);
    assert.equal(r.screen, base.screen, variant);
    assert.equal(r.entityId, base.entityId, variant);
  }
});

test('empty and nullish input are safe', () => {
  for (const v of ['', '/', null, undefined]) {
    const r = resolveRouteContext(v);
    assert.equal(r.confidence, 'low');
  }
});

test('titleize', () => {
  assert.equal(titleize('lead-details'), 'Lead Details');
  assert.equal(titleize('purchase_order'), 'Purchase Order');
  assert.equal(titleize(null), null);
});
