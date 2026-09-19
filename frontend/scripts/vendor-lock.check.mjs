// Checks for src/lib/vendors/workspaceLock.js — `npm run check:vendor-lock`.
//
// This one function decides what a vendor sees in their own portal and what an
// admin sees in the vendor workspace. Both portals and both admin workspaces
// call it, so getting it wrong shows the entire system to a company that has
// not onboarded — which is exactly what happened.
//
// The bug it guards: the rule used to be
//
//     if (vendor.status === VENDOR_ACTIVE) return true
//
// on the assumption that "Active" and "onboarding approved" mean the same
// thing. In real data they disagree constantly — a vendor set Active by hand,
// or activated before the wizard was finished, sits Active with its onboarding
// still In_Progress at step 1 — and every one of those was unlocked.

import {
  isWorkspaceUnlocked,
  isSectionUnlocked,
  isPortalSectionUnlocked,
  lockNav,
} from '../src/lib/vendors/workspaceLock.js'

let failures = 0
const check = (name, cond, extra = '') => {
  if (!cond) failures++
  console.log(`${cond ? 'ok  ' : 'FAIL'}  ${name}${extra ? '  — ' + extra : ''}`)
}
const group = (n) => console.log(`\n── ${n}`)

const vendor = (status) => ({ status })
const ob = (status) => ({ status })

/* ── the onboarding decides ──────────────────────────────────────────────── */
group('an unfinished onboarding keeps the workspace locked')

check('Active + In_Progress is LOCKED',
  isWorkspaceUnlocked(vendor('Active'), ob('In_Progress')) === false,
  'the reported case — status said Active, onboarding was at step 1')

check('Active + Submitted is LOCKED',
  isWorkspaceUnlocked(vendor('Active'), ob('Submitted')) === false,
  'submitted is not approved')

check('Active + Under_Review is LOCKED',
  isWorkspaceUnlocked(vendor('Active'), ob('Under_Review')) === false)

check('Active + Rejected is LOCKED',
  isWorkspaceUnlocked(vendor('Active'), ob('Rejected')) === false)

check('Active + On_Hold is LOCKED',
  isWorkspaceUnlocked(vendor('Active'), ob('On_Hold')) === false)

/* ── and an approved one opens it ────────────────────────────────────────── */
group('an approved onboarding unlocks it')

check('Active + Approved is UNLOCKED',
  isWorkspaceUnlocked(vendor('Active'), ob('Approved')) === true)

check('an approved onboarding wins even if the status lags',
  isWorkspaceUnlocked(vendor('Pending_Approval'), ob('Approved')) === true,
  'approval may not have flipped the status column yet')

/* ── no onboarding is the least onboarded there is ───────────────────────── */
group('a vendor with no onboarding at all')

check('Active with NO onboarding is LOCKED',
  isWorkspaceUnlocked(vendor('Active'), null) === false,
  'no wizard ever started is not a way past the wizard')

check('Draft with no onboarding is LOCKED',
  isWorkspaceUnlocked(vendor('Draft'), null) === false)

check('no vendor at all is LOCKED',
  isWorkspaceUnlocked(null, ob('Approved')) === false)

check('the status column can never unlock on its own',
  ['Active', 'Draft', 'Pending_Approval', 'Inactive', 'On_Hold', 'Rejected', 'Blacklisted']
    .every(st => isWorkspaceUnlocked(vendor(st), null) === false),
  'onboarding is the only key')

/* ── what survives while locked ──────────────────────────────────────────── */
group('what a locked vendor can still reach')

check('the vendor portal keeps Dashboard', isPortalSectionUnlocked('dashboard', false))
check('the vendor portal keeps Onboarding', isPortalSectionUnlocked('onboarding', false))
check('the vendor portal hides Purchase Orders', !isPortalSectionUnlocked('purchase-orders', false))
check('the vendor portal hides Payments', !isPortalSectionUnlocked('payments', false))
check('the vendor portal hides Permits', !isPortalSectionUnlocked('permits', false))

check('the ADMIN workspace keeps Documents', isSectionUnlocked('documents', false),
  'an admin has to review what was uploaded in order to approve it')
check('the ADMIN workspace keeps Overview', isSectionUnlocked('overview', false))
check('the ADMIN workspace hides Gate Log', !isSectionUnlocked('gate-log', false))

check('unlocking restores everything',
  isPortalSectionUnlocked('purchase-orders', true) && isSectionUnlocked('gate-log', true))

/* ── and the nav filter that applies it ──────────────────────────────────── */
// lockNav serves the two ADMIN workspaces, so it filters on the admin list
// (overview / profile / contacts / documents). The vendor portal filters with
// isPortalSectionUnlocked instead — a shorter list, checked above.
group('the admin nav is actually filtered')

const NAV = [
  { group: 'Onboarding', items: [{ key: 'overview' }, { key: 'documents' }] },
  { group: 'Commercial', items: [{ key: 'purchase-orders' }, { key: 'payments' }] },
]

const locked = lockNav(NAV, false, (it) => it.key)
check('a locked nav drops the commercial group entirely',
  locked.groups.length === 1 && locked.groups[0].group === 'Onboarding')
check('and counts what it hid', locked.hidden === 2,
  'the count feeds the line explaining where they went')

const open = lockNav(NAV, true, (it) => it.key)
check('an unlocked nav is untouched', open.groups.length === 2 && open.hidden === 0)

console.log(failures ? `\n${failures} FAILED` : '\nall passed')
process.exit(failures ? 1 : 0)
