/**
 * Where a meeting lives — one table, read by everything that links to one.
 *
 * WHY THIS FILE EXISTS. The same meeting screens are mounted at three paths:
 * `/app/meetings` (the company-wide module), `/app/purchase/kickoff` and
 * `/app/tpv/kickoff`. Which one the user is in decides every link on the page,
 * and that decision used to be made TWICE — once in `useMeetingModule()` for
 * components and once in `meetingBase()` for the API layer.
 *
 * The two disagreed. `useMeetingModule()` knew about `/app/meetings`;
 * `meetingBase()` only ever tested for `/app/purchase` and fell through to
 * `/app/tpv` for everything else. Every link built from it — twenty call sites
 * across the three shared pages — threw a user standing in the company-wide
 * Meetings module straight back into TPV. Meetings had been given its own
 * module, its own path and its own sidebar entry, and the moment you clicked
 * anything inside it you were in somebody else's (SIR-000030).
 *
 * So: one table. A module cannot be half-added any more, because there is only
 * one place to add it and every consumer reads the same row.
 *
 * NOTE THE PATH SHAPES DIFFER. Under Purchase and TPV a meeting is
 * `<base>/kickoff/<id>`; in its own module it is `/app/meetings/<id>`. That is
 * why these are builders rather than a base string to concatenate — the old
 * `${meetingBase()}/kickoff/${id}` could not have produced the right URL for
 * the company-wide module even with the missing branch added.
 */

/** Ordered: the first match wins, so the catch-all must stay last. */
const MODULES = [
  {
    key: 'purchase',
    match: (p) => p.startsWith('/app/purchase'),
    base: '/app/purchase',
    label: 'Purchase',
    // Purchase meetings are scoped to a vendor and carry no project link, so
    // the project filter is hidden rather than shown permanently empty.
    hasProjects: false,
    list: '/app/purchase/kickoff',
    create: '/app/purchase/kickoff/new',
    detail: (id) => `/app/purchase/kickoff/${id}`,
    edit: (id) => `/app/purchase/kickoff/${id}/edit`,
    registers: (register) => `/app/purchase/meetings/registers${register ? `/${register}` : ''}`,
  },

  // The company-wide module. Same engine, same screens, no vendor module in
  // the way — an HR catch-up has no business being scheduled from inside TPV.
  // Must be tested before the catch-all below, which is the bug this table was
  // built to make impossible.
  {
    key: 'meetings',
    match: (p) => p.startsWith('/app/meetings'),
    base: '/app',
    label: 'Meetings',
    hasProjects: true,
    list: '/app/meetings',
    create: '/app/meetings/new',
    detail: (id) => `/app/meetings/${id}`,
    edit: (id) => `/app/meetings/${id}/edit`,
    registers: (register) => `/app/meetings/registers${register ? `/${register}` : ''}`,
  },

  {
    key: 'shared',
    match: () => true,
    base: '/app/tpv',
    label: 'Meetings',
    hasProjects: true,
    list: '/app/tpv/kickoff',
    create: '/app/tpv/kickoff/new',
    detail: (id) => `/app/tpv/kickoff/${id}`,
    edit: (id) => `/app/tpv/kickoff/${id}/edit`,
    registers: (register) => `/app/tpv/meetings/registers${register ? `/${register}` : ''}`,
  },
]

/** The module a path belongs to. Never null — the last row matches anything. */
export function meetingModuleFor(pathname) {
  const path = typeof pathname === 'string' ? pathname : ''

  return MODULES.find((m) => m.match(path)) ?? MODULES[MODULES.length - 1]
}

/** Exported for the test that checks every module round-trips its own links. */
export const MEETING_MODULES = MODULES
