// Checks for src/lib/sire/resolveRouteContext.js — `npm run check:sire`.
//
// Report Issue files a ticket against whatever this resolver says the user was
// looking at. When it returns nothing, the issue lands with no section, screen
// or record and a developer has to guess (SIR-000011).
//
// The trap it guards is the one that caused that bug: the exact matcher needs
// the same number of path segments, so every tabbed workspace — vendors, TPV,
// anything that puts a tab in the URL — resolved to its module and nothing
// else. The prefix pass fixes the class; these cases pin both that it works and
// that it did not become a catch-all that mislabels unrelated pages.

import { resolveRouteContext } from '../src/lib/sire/resolveRouteContext.js'

let failures = 0
const check = (name, cond, extra = '') => {
  if (!cond) failures++
  console.log(`${cond ? 'ok  ' : 'FAIL'}  ${name}${extra ? '  — ' + extra : ''}`)
}
const group = (n) => console.log(`\n── ${n}`)

/* ── exactly-mapped routes keep resolving as before ──────────────────────── */
group('mapped routes are untouched')

const lead = resolveRouteContext('/app/sales/leads/10452')
check('a mapped record route still matches exactly', lead.source === 'route')
check('its record is identified', lead.entityLabel === 'Lead #10452')
check('it stays high confidence', lead.confidence === 'high')

const vendor = resolveRouteContext('/app/purchase/vendors/1')
check('the vendor root still matches exactly', vendor.source === 'route')
check('its screen is the mapped one', vendor.screen === 'vendor-details')

/* ── the workspace tabs SIR-000011 was filed from ────────────────────────── */
group('a tab under a mapped route inherits it')

const tab = resolveRouteContext('/app/purchase/vendors/1/customer')
check('the module resolves', tab.moduleLabel === 'Purchase')
check('the section resolves', tab.sectionLabel === 'Vendors',
  'this was null — the whole point of the report')
check('the tab names the screen', tab.screenLabel === 'Customer')
check('the record survives the extra segment', tab.entityId === '1')
check('the record type survives too', tab.entityType === 'purchase_vendor')
check('it is marked as inherited, not mapped', tab.source === 'route-prefix')
check('a derived screen is never high confidence', tab.confidence === 'medium',
  'the reporter must still be offered the correction box')

const tpv = resolveRouteContext('/app/tpv/vendors/7/medical')
check('the same holds in TPV', tpv.sectionLabel === 'Vendors' && tpv.entityId === '7')

const nested = resolveRouteContext('/app/purchase/vendors/1/documents/57')
check('a trailing id does not become part of the screen name',
  nested.screenLabel === 'Documents', `got ${nested.screenLabel}`)

/* ── and it must not turn into a catch-all ───────────────────────────────── */
group('inheritance stays honest')

const unknown = resolveRouteContext('/app/nothing/at/all')
check('an unmapped module resolves to nothing', unknown.source === 'none',
  '/app is mapped, so inheriting from it would label every page "Dashboard"')
check('no section is invented for it', unknown.sectionLabel === null)

const dash = resolveRouteContext('/app/dashboard')
check('the dashboard itself still resolves', dash.source === 'route')

console.log(failures ? `\n${failures} FAILED` : '\nall passed')
process.exit(failures ? 1 : 0)
