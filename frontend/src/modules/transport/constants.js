/**
 * Transport OS shared vocabulary for the UI.
 *
 * Mirrors the backend enums exactly (App\Support\Transport\*). Kept in one file
 * so a status chip, a filter and a form dropdown cannot disagree about what the
 * states are — the drift the team conventions warn about when a UI keeps its own
 * copy of a field list.
 */

/* ── Order (SM-ORD, Step 11) ──────────────────────────────────────────── */

export const ORDER_STATUS = {
  DRAFT: 'draft',
  SUBMITTED: 'submitted',
  APPROVED: 'approved',
  REJECTED: 'rejected',
}

export const ORDER_STATUS_LABEL = {
  draft: 'Draft',
  submitted: 'Submitted',
  approved: 'Approved',
  rejected: 'Rejected',
}

/** Chip colours follow the CRM's convention: neutral → in-flight → good → bad. */
export const orderStatusCfg = (s) => ({
  draft:     { label: 'Draft',     color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' },
  submitted: { label: 'Submitted', color: '#38bdf8', bg: 'rgba(56,189,248,0.14)' },
  approved:  { label: 'Approved',  color: '#34d399', bg: 'rgba(52,211,153,0.14)' },
  rejected:  { label: 'Rejected',  color: '#f87171', bg: 'rgba(248,113,113,0.14)' },
}[s] || { label: s || '—', color: 'var(--text-muted)', bg: 'var(--bg-input)' })

/** Transitions the API will accept, so the UI never offers one it will refuse. */
export const ORDER_TRANSITIONS = {
  draft:     [{ to: 'submitted', label: 'Submit for validation' }],
  submitted: [
    { to: 'approved', label: 'Approve' },
    { to: 'rejected', label: 'Reject', needsReason: true },
    { to: 'draft',    label: 'Return to draft' },
  ],
  approved:  [],
  rejected:  [],
}

/* ── Priority (OPS §9 / BRW-018) — exactly four ───────────────────────── */

export const ORDER_PRIORITIES = ['Normal', 'Priority', 'Urgent', 'Critical']

export const priorityCfg = (p) => ({
  Normal:   { color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' },
  Priority: { color: '#38bdf8', bg: 'rgba(56,189,248,0.14)' },
  Urgent:   { color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' },
  Critical: { color: '#f87171', bg: 'rgba(248,113,113,0.16)' },
}[p] || { color: 'var(--text-muted)', bg: 'var(--bg-input)' })

/* ── Source (OPS §6) — "Every creation must identify source" ──────────── */

export const ORDER_SOURCES = [
  { value: 'manual',      label: 'Manual (Operations)' },
  { value: 'sales',       label: 'Sales' },
  { value: 'customer',    label: 'Customer' },
  { value: 'api',         label: 'API' },
  { value: 'recurring',   label: 'Recurring order' },
  { value: 'bulk_import', label: 'Bulk import' },
]

/* ── Trip (Step 9's locked machine) ───────────────────────────────────── */

export const TRIP_STATUS = {
  DRAFT: 'draft',
  VIABILITY_PENDING: 'viability_pending',
}

export const TRIP_STATUS_LABEL = {
  draft: 'Draft',
  viability_pending: 'Viability pending',
  approved: 'Approved',
  allocated: 'Allocated',
  pretrip_ok: 'Ready to dispatch',
  dispatched: 'Dispatched',
  in_transit: 'In transit',
  arrived: 'Arrived',
  delivered: 'Delivered',
  pod_pending: 'POD pending',
  pod_verified: 'POD verified',
  billable: 'Billable',
  billed: 'Billed',
  collection_pending: 'Collection pending',
  settlement_pending: 'Settlement pending',
  closed: 'Closed',
}

/**
 * The trip's journey, as the five things a person actually does to it.
 *
 * This is a PRESENTATION grouping, not the state machine. The machine has 16
 * states; an operator has five jobs, and several states belong to one job (a
 * trip is "being set up" whether it is draft, awaiting viability, or approved).
 * Grouping them is what makes the page walkable — 16 chips explain nothing.
 *
 * `states` is what counts as DONE for that step. A step with no built states is
 * marked `built: false` and rendered as still to come, because the honest answer
 * to "what happens after dispatch?" is "that part is not built yet", and a
 * tracker that quietly stopped at four steps would imply dispatch is the end.
 */
export const TRIP_JOURNEY = [
  {
    key: 'setup',
    label: 'Trip set up',
    blurb: 'The trip exists and has been approved to run.',
    states: ['approved', 'allocated', 'pretrip_ok', 'dispatched'],
    active: ['draft', 'viability_pending'],
    built: true,
  },
  {
    key: 'crew',
    label: 'Vehicle & driver',
    blurb: 'A vehicle and a driver are assigned to the trip.',
    states: ['allocated', 'pretrip_ok', 'dispatched'],
    active: ['approved'],
    built: true,
  },
  {
    key: 'checks',
    label: 'Pre-trip checks',
    blurb: 'Everything is verified as fit to leave — papers, vehicle, driver.',
    states: ['pretrip_ok', 'dispatched'],
    active: ['allocated'],
    built: true,
  },
  {
    key: 'dispatch',
    label: 'Dispatch',
    blurb: 'The trip is released, with its departure and arrival times fixed.',
    states: ['dispatched'],
    active: ['pretrip_ok'],
    built: true,
  },
  {
    key: 'journey',
    label: 'On the road',
    blurb: 'Tracking the trip, delivery and proof of delivery.',
    states: [],
    active: [],
    built: false,
  },
]

/**
 * Where a trip stands on each step: 'done', 'current', 'todo' or 'later'.
 *
 * 'current' is the step being worked on right now; 'later' marks the steps that
 * are not built, so nobody reads a grey circle as "this trip is behind".
 */
export const tripJourneyState = (step, status) => {
  if (!step.built) return 'later'
  if (step.states.includes(status)) return 'done'
  if (step.active.includes(status)) return 'current'
  return 'todo'
}

/**
 * Filter chips list every state so a trip is never invisible, but only draft and
 * viability_pending are reachable today — the rest arrive with their tickets.
 */
export const TRIP_STATUSES = Object.keys(TRIP_STATUS_LABEL)

export const tripStatusCfg = (s) => {
  if (s === 'draft') return { label: 'Draft', color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' }
  if (s === 'viability_pending') return { label: 'Viability pending', color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' }
  if (s === 'closed') return { label: 'Closed', color: '#34d399', bg: 'rgba(52,211,153,0.14)' }
  return { label: TRIP_STATUS_LABEL[s] || s || '—', color: '#38bdf8', bg: 'rgba(56,189,248,0.14)' }
}

/* ── Formatting ───────────────────────────────────────────────────────── */

export const fmtMoney = (v, ccy = 'INR') =>
  v == null || v === ''
    ? '—'
    : new Intl.NumberFormat('en-IN', { style: 'currency', currency: ccy, maximumFractionDigits: 2 }).format(Number(v))

export const fmtDateTime = (d) =>
  !d ? '—' : new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })

export const fmtDate = (d) =>
  !d ? '—' : new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })

/** A location object → one readable line. */
export const fmtLocation = (loc) => {
  if (!loc) return '—'
  return [loc.address, loc.city, loc.state, loc.pincode].filter(Boolean).join(', ') || '—'
}

/* ══════════════════════════════════════════════════════════════════════════
   MASTER DATA — Vehicle (SNG-TRN-003) and Driver (SNG-TRN-004)
   ══════════════════════════════════════════════════════════════════════════ */

/* ── Vehicle status — STOS-FLEET §7, all 13 ───────────────────────────── */

export const VEHICLE_STATUS_LABEL = {
  new: 'New',
  available: 'Available',
  allocated: 'Allocated',
  in_transit: 'In transit',
  idle: 'Idle',
  maintenance_due: 'Maintenance due',
  under_maintenance: 'Under maintenance',
  breakdown: 'Breakdown',
  compliance_blocked: 'Compliance blocked',
  accident: 'Accident',
  suspended: 'Suspended',
  sold: 'Sold',
  retired: 'Retired',
}

/**
 * UX §33 asks for human language, not SCREAMING_SNAKE, and UX §129 forbids
 * colour as the only signal — every chip renders its LABEL as well as its
 * colour, so the two travel together.
 */
export const vehicleStatusCfg = (s) => ({
  new:                { label: 'New',                color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' },
  available:          { label: 'Available',          color: '#34d399', bg: 'rgba(52,211,153,0.14)' },
  allocated:          { label: 'Allocated',          color: '#38bdf8', bg: 'rgba(56,189,248,0.14)' },
  in_transit:         { label: 'In transit',         color: '#818cf8', bg: 'rgba(129,140,248,0.14)' },
  idle:               { label: 'Idle',               color: '#a78bfa', bg: 'rgba(167,139,250,0.14)' },
  maintenance_due:    { label: 'Maintenance due',    color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' },
  under_maintenance:  { label: 'Under maintenance',  color: '#fb923c', bg: 'rgba(251,146,60,0.16)' },
  breakdown:          { label: 'Breakdown',          color: '#f87171', bg: 'rgba(248,113,113,0.16)' },
  compliance_blocked: { label: 'Compliance blocked', color: '#f87171', bg: 'rgba(248,113,113,0.16)' },
  accident:           { label: 'Accident',           color: '#ef4444', bg: 'rgba(239,68,68,0.18)' },
  suspended:          { label: 'Suspended',          color: '#fb7185', bg: 'rgba(251,113,133,0.16)' },
  sold:               { label: 'Sold',               color: '#64748b', bg: 'rgba(100,116,139,0.14)' },
  retired:            { label: 'Retired',            color: '#64748b', bg: 'rgba(100,116,139,0.14)' },
}[s] || { label: s || '—', color: 'var(--text-muted)', bg: 'var(--bg-input)' })

/**
 * Only the moves the master itself owns — mirrors VehicleStatus::TRANSITIONS.
 * allocated / in_transit belong to allocation and dispatch, so the UI never
 * offers them; a button the API would refuse is the "fake affordance" the team
 * conventions forbid.
 */
export const VEHICLE_TRANSITIONS = {
  new:       [{ to: 'available', label: 'Commission (make available)' }, { to: 'retired', label: 'Retire' }],
  available: [{ to: 'suspended', label: 'Suspend' }, { to: 'sold', label: 'Mark sold' }, { to: 'retired', label: 'Retire' }],
  suspended: [{ to: 'available', label: 'Reinstate' }, { to: 'sold', label: 'Mark sold' }, { to: 'retired', label: 'Retire' }],
}

/** STOS-FLEET §10 — the six ownership types, no others. */
export const VEHICLE_OWNERSHIP = [
  { value: 'owned', label: 'Owned' },
  { value: 'financed', label: 'Financed' },
  { value: 'leased', label: 'Leased' },
  { value: 'contracted', label: 'Contracted' },
  { value: 'attached', label: 'Attached' },
  { value: 'other', label: 'Other' },
]

/* ── Driver — two axes (BO-009 lifecycle, STOS-DB §44 availability) ───── */

export const DRIVER_STATUS_LABEL = { active: 'Active', inactive: 'Inactive', blocked: 'Blocked' }

export const driverStatusCfg = (s) => ({
  active:   { label: 'Active',   color: '#34d399', bg: 'rgba(52,211,153,0.14)' },
  inactive: { label: 'Inactive', color: '#64748b', bg: 'rgba(100,116,139,0.14)' },
  blocked:  { label: 'Blocked',  color: '#f87171', bg: 'rgba(248,113,113,0.16)' },
}[s] || { label: s || '—', color: 'var(--text-muted)', bg: 'var(--bg-input)' })

export const DRIVER_AVAILABILITY_LABEL = {
  available: 'Available',
  assigned: 'Assigned',
  on_trip: 'On trip',
  on_leave: 'On leave',
  absent: 'Absent',
  suspended: 'Suspended',
  unavailable: 'Unavailable',
}

export const driverAvailabilityCfg = (a) => ({
  available:   { label: 'Available',   color: '#34d399', bg: 'rgba(52,211,153,0.14)' },
  assigned:    { label: 'Assigned',    color: '#38bdf8', bg: 'rgba(56,189,248,0.14)' },
  on_trip:     { label: 'On trip',     color: '#818cf8', bg: 'rgba(129,140,248,0.14)' },
  on_leave:    { label: 'On leave',    color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' },
  absent:      { label: 'Absent',      color: '#fb923c', bg: 'rgba(251,146,60,0.16)' },
  suspended:   { label: 'Suspended',   color: '#fb7185', bg: 'rgba(251,113,133,0.16)' },
  unavailable: { label: 'Unavailable', color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' },
}[a] || { label: a || '—', color: 'var(--text-muted)', bg: 'var(--bg-input)' })

/** Mirrors DriverStatus::TRANSITIONS and DriverAvailability::TRANSITIONS. */
export const DRIVER_STATUS_TRANSITIONS = {
  active:   [{ to: 'inactive', label: 'Deactivate' }, { to: 'blocked', label: 'Block' }],
  inactive: [{ to: 'active', label: 'Reactivate' }],
  blocked:  [{ to: 'active', label: 'Unblock' }, { to: 'inactive', label: 'Deactivate' }],
}

export const DRIVER_AVAILABILITY_TRANSITIONS = {
  available:   [{ to: 'on_leave', label: 'On leave' }, { to: 'absent', label: 'Absent' }, { to: 'suspended', label: 'Suspend' }, { to: 'unavailable', label: 'Unavailable' }],
  on_leave:    [{ to: 'available', label: 'Back from leave' }, { to: 'absent', label: 'Absent' }, { to: 'suspended', label: 'Suspend' }, { to: 'unavailable', label: 'Unavailable' }],
  absent:      [{ to: 'available', label: 'Back' }, { to: 'on_leave', label: 'On leave' }, { to: 'suspended', label: 'Suspend' }, { to: 'unavailable', label: 'Unavailable' }],
  suspended:   [{ to: 'available', label: 'Lift suspension' }, { to: 'unavailable', label: 'Unavailable' }],
  unavailable: [{ to: 'available', label: 'Make available' }, { to: 'on_leave', label: 'On leave' }, { to: 'absent', label: 'Absent' }, { to: 'suspended', label: 'Suspend' }],
  // assigned / on_trip deliberately absent — SNG-TRN-009 and dispatch own them.
}

/* ── Compliance (CMP §23), derived on the server ──────────────────────── */

export const complianceCfg = (c) => ({
  compliant:     { label: 'Compliant',     color: '#34d399', bg: 'rgba(52,211,153,0.14)' },
  expiring:      { label: 'Expiring',      color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' },
  non_compliant: { label: 'Non-compliant', color: '#f87171', bg: 'rgba(248,113,113,0.16)' },
  blocked:       { label: 'Blocked',       color: '#ef4444', bg: 'rgba(239,68,68,0.18)' },
  under_review:  { label: 'Under review',  color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' },
}[c] || { label: c || '—', color: 'var(--text-muted)', bg: 'var(--bg-input)' })

/* ── Documents (ENUM-006) ─────────────────────────────────────────────── */

export const VEHICLE_DOCUMENT_TYPES = [
  { value: 'insurance', label: 'Insurance' },
  { value: 'fitness', label: 'Fitness certificate' },
  { value: 'permit', label: 'Permit' },
  // ENUM-006 has no dedicated value for RC, PUC, National Permit, Tax or
  // Inspection — STOS-FLEET §11 names all five. They are filed as the catch-all
  // `vehicle_doc` with their name in the document number, because extending a
  // LOCKED registry enum is a Product+Compliance change, not a developer's.
  { value: 'vehicle_doc', label: 'Other vehicle document (RC, PUC, tax…)' },
  { value: 'other', label: 'Other' },
]

export const DRIVER_DOCUMENT_TYPES = [
  { value: 'driver_doc', label: 'Driver document (licence scan, ID, training…)' },
  { value: 'fitness', label: 'Medical / fitness' },
  { value: 'permit', label: 'Permit' },
  { value: 'other', label: 'Other' },
]

export const DOCUMENT_TYPE_LABEL = {
  lr: 'LR / Bilty', ewaybill: 'E-Way Bill', invoice: 'Invoice', pod: 'Proof of delivery',
  driver_doc: 'Driver document', vehicle_doc: 'Vehicle document', insurance: 'Insurance',
  permit: 'Permit', fitness: 'Fitness certificate', other: 'Other',
}

/**
 * Expiry state for a document row.
 *
 * The server already computes days remaining; this only decides how to show it.
 * FLEET §13's default warning window is 30 days — the one value CMP §18 and
 * FLEET §13 share. UX §129: the label always accompanies the colour.
 */
export const expiryCfg = (validUntil, windowDays = 30) => {
  if (!validUntil) return { label: 'No expiry', color: '#64748b', bg: 'rgba(100,116,139,0.14)', days: null }
  const days = Math.round((new Date(validUntil) - new Date()) / 86400000)
  if (days < 0) return { label: `Expired ${Math.abs(days)}d ago`, color: '#f87171', bg: 'rgba(248,113,113,0.16)', days }
  if (days <= windowDays) return { label: `Expires in ${days}d`, color: '#fbbf24', bg: 'rgba(251,191,36,0.16)', days }
  return { label: 'Valid', color: '#34d399', bg: 'rgba(52,211,153,0.14)', days }
}


/* ══════════════════════════════════════════════════════════════════════════
   PRE-TRIP CHECKS — SNG-TRN-010
   ══════════════════════════════════════════════════════════════════════════ */

/**
 * Readiness of a trip's whole checklist — STOS-OPS §29, verbatim.
 *
 * Five values are declared; only four are reachable. OVERRIDE_REQUIRED is P1
 * everywhere the package raises it (RTM CMP-007, BRW-049, PLN-007), so the
 * server never returns it — it is listed here so the vocabulary matches §29 and
 * a later ticket inherits the name instead of inventing a second one.
 *
 * UX §129 — colour is never the only signal, so every entry carries a label.
 */
export const PRETRIP_READINESS_LABEL = {
  not_started: 'Not started',
  in_progress: 'In progress',
  ready: 'Ready',
  blocked: 'Blocked',
  override_required: 'Override required',
}

export const pretripReadinessCfg = (s) => {
  if (s === 'ready') return { label: 'Ready', color: '#34d399', bg: 'rgba(52,211,153,0.14)' }
  if (s === 'blocked') return { label: 'Blocked', color: '#f87171', bg: 'rgba(248,113,113,0.16)' }
  if (s === 'in_progress') return { label: 'In progress', color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' }
  if (s === 'not_started') return { label: 'Not started', color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' }
  return { label: PRETRIP_READINESS_LABEL[s] || s || '—', color: '#38bdf8', bg: 'rgba(56,189,248,0.14)' }
}

/**
 * The outcome of ONE check — STOS-FLEET §88's four values, plus `pending`.
 *
 * §88 grades a completed inspection and has no word for one not yet done, so
 * `pending` is this module's addition and is flagged as such server-side.
 *
 * The colours carry BRW-052's distinction, which is the whole point:
 * critical_fail BLOCKS dispatch, fail only warns.
 */
export const PRETRIP_RESULT_LABEL = {
  pending: 'Pending',
  pass: 'Pass',
  pass_warning: 'Pass with warning',
  fail: 'Fail',
  critical_fail: 'Critical fail',
}

export const pretripResultCfg = (r) => {
  if (r === 'pass') return { label: 'Pass', color: '#34d399', bg: 'rgba(52,211,153,0.14)' }
  if (r === 'pass_warning') return { label: 'Pass with warning', color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' }
  if (r === 'fail') return { label: 'Fail', color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' }
  if (r === 'critical_fail') return { label: 'Critical fail', color: '#f87171', bg: 'rgba(248,113,113,0.16)' }
  return { label: 'Pending', color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' }
}

/**
 * OPS §28's five categories, in the document's order.
 *
 * Used only to order the groups on screen. The server sends `category` and
 * `category_label` on every check, so an unrecognised category still renders
 * rather than vanishing — a check the UI cannot place is exactly the one worth
 * showing.
 */
export const PRETRIP_CATEGORY_ORDER = ['commercial', 'driver', 'vehicle', 'reefer', 'documents']

/* ── Dispatch (FRS TRP-P0-006) ────────────────────────────────────────── */

/**
 * The five fields TRP-P0-006 freezes at release, in the order it lists them.
 *
 * `type` drives the input; `hint` exists because a frozen field is one a person
 * cannot quietly correct later, and they should know that before they type.
 */
export const DISPATCH_FIELDS = [
  { key: 'planned_departure_at', label: 'ETD — planned departure', type: 'datetime-local', hint: 'Locked once you dispatch. Changing it after that needs a reason.' },
  { key: 'planned_arrival_at', label: 'ETA — planned arrival', type: 'datetime-local', hint: 'How long the trip should take is worked out from these two times.' },
  { key: 'pickup_contact', label: 'Pickup contact', type: 'text', hint: 'Name and number the driver should call.' },
  { key: 'dispatch_destination', label: 'Destination', type: 'text', hint: 'Where this vehicle is actually going.' },
  { key: 'dispatch_instructions', label: 'Instructions for the driver', type: 'textarea', hint: 'Gate, seal, documents — anything the driver needs on arrival.' },
]

export const DISPATCH_FIELD_LABEL = Object.fromEntries(DISPATCH_FIELDS.map((f) => [f.key, f.label]))

/**
 * TAT, derived rather than stored — the server sends `turnaround_hours` and
 * DispatchScope explains why no column holds it.
 */
export const fmtTurnaround = (h) => {
  if (h === null || h === undefined) return '—'
  const n = Number(h)
  if (!Number.isFinite(n)) return '—'
  if (n < 1) return `${Math.round(n * 60)} min`
  const days = Math.floor(n / 24)
  const hours = Math.round(n % 24)
  return days ? `${days}d ${hours}h` : `${n % 1 === 0 ? n : n.toFixed(1)}h`
}

/**
 * `datetime-local` will not accept an ISO string with a zone, and sending its
 * output back raw loses the seconds the API expects. These two are the pair.
 */
export const toLocalInput = (v) => {
  if (!v) return ''
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export const fromLocalInput = (v) => (v ? v.replace('T', ' ') + ':00' : null)

/** A dispatch history entry — version 1 is the release, the rest are amendments. */
export const dispatchVersionCfg = (type, version) =>
  type === 'release'
    ? { label: 'Dispatched', color: '#34d399', bg: 'rgba(52,211,153,0.14)' }
    : { label: `Change ${version}`, color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' }

/* ── Advances (SNG-TRN-011) ───────────────────────────────────────────── */

/**
 * ENUM-002's six values. All six are coloured even though only `requested`,
 * `approved` and `rejected` are reachable today — `paid`, `adjusted` and
 * `recovery` belong to TRP-P0-008 and SNG-TRN-017. A status arriving with no
 * colour renders as a bare string, which is how a new state reaches production
 * looking broken.
 */
export const advanceStatusCfg = (s) => ({
  requested: { label: 'Requested', color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' },
  approved: { label: 'Approved', color: '#34d399', bg: 'rgba(52,211,153,0.14)' },
  rejected: { label: 'Rejected', color: '#f87171', bg: 'rgba(248,113,113,0.14)' },
  paid: { label: 'Paid', color: '#34d399', bg: 'rgba(52,211,153,0.14)' },
  adjusted: { label: 'Adjusted', color: '#38bdf8', bg: 'rgba(56,189,248,0.14)' },
  recovery: { label: 'Recovery', color: '#fb923c', bg: 'rgba(251,146,60,0.16)' },
}[s] ?? { label: s || '—', color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' })

/* ── Trip documents and POD (SNG-TRN-014) ─────────────────────────────── */

export const tripDocStatusCfg = (s) => ({
  received: { label: 'Awaiting check', color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' },
  verified: { label: 'Verified', color: '#34d399', bg: 'rgba(52,211,153,0.14)' },
  rejected: { label: 'Rejected', color: '#f87171', bg: 'rgba(248,113,113,0.14)' },
}[s] ?? { label: s || '—', color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' })

/** ENUM-006's ten values, for the document-type picker and for display. */
export const DOC_TYPE_LABEL = {
  lr: 'Lorry receipt', ewaybill: 'E-way bill', invoice: 'Invoice', pod: 'Proof of delivery',
  delivery_order: 'Delivery order', driver_doc: 'Driver document', vehicle_doc: 'Vehicle document',
  insurance: 'Insurance', permit: 'Permit', fitness: 'Fitness certificate', other: 'Other',
}

/* ── Collections (SNG-TRN-016) ────────────────────────────────────────── */

export const collectionStatusCfg = (s) => ({
  pending: { label: 'Outstanding', color: '#fbbf24', bg: 'rgba(251,191,36,0.16)' },
  part_paid: { label: 'Part paid', color: '#38bdf8', bg: 'rgba(56,189,248,0.14)' },
  settled: { label: 'Settled', color: '#34d399', bg: 'rgba(52,211,153,0.14)' },
}[s] ?? { label: s || '—', color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' })

/** Bytes → a size somebody can read. */
export const fmtBytes = (n) => {
  if (n == null) return '—'
  const kb = Number(n) / 1024
  return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`
}
