// STOS (Sangoé Transport OS) — /api/v1/* (owner: Developer 2, Fleet & Telemetry)
// Backend wraps responses in { status, message, data }; we unwrap to `data`.
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

const unwrap = (r) => r.data?.data ?? r.data

export const STOS_ACCENT = '#06b6d4'
export const STOS_GRADIENT = 'linear-gradient(135deg,#0891b2,#0e7490)'

export const stosApi = {
  fleet: {
    // The status grid + the tiles above it.
    grid: (params = {}) => api.get('/v1/fleet/vehicles', { params }).then(unwrap).catch(handleErr),

    // Rule-based readiness. Recommends; never allocates — a trip belongs to
    // Dispatch, so nothing here writes an assignment.
    eligible: (params = {}) => api.get('/v1/fleet/vehicles/eligible', { params }).then(unwrap).catch(handleErr),

    // Tier-1 telemetry only. Cheap enough to poll; never touches history.
    liveStatus: (id) => api.get(`/v1/fleet/vehicles/${id}/live-status`).then(unwrap).catch(handleErr),

    // Accepts an id or a registration number — a person types the plate.
    passport: (key) => api.get(`/v1/fleet/vehicles/${encodeURIComponent(key)}/passport`).then(unwrap).catch(handleErr),

    // Step 1 — onboarding. Creating a vehicle also initialises its live-status
    // row, so telemetry has a target from the moment the truck exists.
    create: (data) => api.post('/v1/fleet/vehicles', data).then(unwrap).catch(handleErr),
    update: (id, data) => api.put(`/v1/fleet/vehicles/${id}`, data).then(unwrap).catch(handleErr),
    // Admin only, and soft — fuel spend and job cards stay attached.
    retire: (id) => api.delete(`/v1/fleet/vehicles/${id}`).then(unwrap).catch(handleErr),

    /**
     * T-56 — the one hand-driven edge of the asset state machine.
     *
     * Only AVAILABLE, IDLE and RETIRED are accepted. Everything else is a
     * consequence of something happening elsewhere, and the server explains
     * which — so a 422 here carries a sentence worth showing the user.
     */
    setStatus: (id, status) =>
      api.patch(`/v1/fleet/vehicles/${id}/status`, { status }).then(unwrap).catch(handleErr),
  },

  fuel: {
    // multipart: the receipt photo rides along with the entry.
    record: (vehicleId, form) =>
      api.post(`/v1/fleet/vehicles/${vehicleId}/fuel`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }).then(unwrap).catch(handleErr),

    exceptions: (params = {}) => api.get('/v1/fleet/fuel/exceptions', { params }).then(unwrap).catch(handleErr),

    // Private disk — the receipt is served through the API, never a public URL.
    receiptUrl: (fuelId) => `/v1/fleet/fuel/${fuelId}/receipt`,
  },

  // Drivers are READ from the CRM's customer/vendor directory — there is no
  // create call, because STOS does not own people. Only the licence/availability
  // overlay is ours to write.
  drivers: {
    list: (params = {}) => api.get('/v1/fleet/drivers', { params }).then(unwrap).catch(handleErr),

    /** Who can take a load right now, and who cannot — with the reason and whose desk owns it. */
    eligible: (params = {}) => api.get('/v1/fleet/drivers/eligible', { params }).then(unwrap).catch(handleErr),
    saveProfile: (source, personId, data) =>
      api.put(`/v1/fleet/drivers/${source}/${personId}`, data).then(unwrap).catch(handleErr),
    // The REGULAR assignment the allocation engine reads. Pass null to clear.
    assign: (source, personId, vehicleId) =>
      api.put(`/v1/fleet/drivers/${source}/${personId}/assign`, { vehicle_id: vehicleId }).then(unwrap).catch(handleErr),
  },

  /**
   * T-57 — statutory paperwork.
   *
   * `file` posts multipart because a driver photographs a certificate at the
   * roadside; everything else is JSON. Uploading never clears a truck — only
   * verifying does, which is why `verify` is a separate call and not a
   * checkbox on the upload.
   */
  documents: {
    forVehicle: (vehicleId) => api.get(`/v1/fleet/vehicles/${vehicleId}/documents`).then(unwrap).catch(handleErr),

    file: (vehicleId, form) => {
      const body = new FormData()
      Object.entries(form).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') body.append(k, v) })

      return api.post(`/v1/fleet/vehicles/${vehicleId}/documents`, body, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }).then(unwrap).catch(handleErr)
    },

    renew: (vehicleId, documentId, form) => {
      const body = new FormData()
      Object.entries(form).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') body.append(k, v) })

      return api.post(`/v1/fleet/vehicles/${vehicleId}/documents/${documentId}/renew`, body, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }).then(unwrap).catch(handleErr)
    },

    verify: (documentId, verdict, reason = null) =>
      api.patch(`/v1/fleet/documents/${documentId}/verify`, { verdict, reason }).then(unwrap).catch(handleErr),
  },

  /**
   * A driver's paperwork (T-43).
   *
   * Addressed by `{source}/{person}` like the rest of the drivers board — Fleet
   * holds no names, so the person is the directory entry and the profile hangs
   * off it. Verification is by document id on its own path, because the verdict
   * is about the evidence and not about whose it is.
   */
  driverDocuments: {
    forDriver: (source, personId) =>
      api.get(`/v1/fleet/drivers/${source}/${personId}/documents`).then(unwrap).catch(handleErr),

    file: (source, personId, form) => {
      const body = new FormData()
      Object.entries(form).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') body.append(k, v) })

      return api.post(`/v1/fleet/drivers/${source}/${personId}/documents`, body, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }).then(unwrap).catch(handleErr)
    },

    renew: (source, personId, documentId, form) => {
      const body = new FormData()
      Object.entries(form).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') body.append(k, v) })

      return api.post(`/v1/fleet/drivers/${source}/${personId}/documents/${documentId}/renew`, body, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }).then(unwrap).catch(handleErr)
    },

    verify: (documentId, verdict, reason = null) =>
      api.patch(`/v1/fleet/driver-documents/${documentId}/verify`, { verdict, reason }).then(unwrap).catch(handleErr),
  },

  gensets: {
    register: (params = {}) => api.get('/v1/fleet/gensets', { params }).then(unwrap).catch(handleErr),
    create:   (data) => api.post('/v1/fleet/gensets', data).then(unwrap).catch(handleErr),
    update:   (id, data) => api.put(`/v1/fleet/gensets/${id}`, data).then(unwrap).catch(handleErr),
    fit:      (id, vehicleId) => api.post(`/v1/fleet/gensets/${id}/fit`, { vehicle_id: vehicleId }).then(unwrap).catch(handleErr),
    unfit:    (id) => api.post(`/v1/fleet/gensets/${id}/unfit`).then(unwrap).catch(handleErr),
  },

  /**
   * Trailers and the coupling between (T-54).
   *
   * A trailer is its own master, not a `vehicle_type` — no engine, so no fuel,
   * no telemetry and no PUC. `history` is an endpoint rather than a field
   * because "which trailer was under that truck on the 14th" is the question
   * the whole feature exists to answer.
   */
  trailers: {
    list:     (params = {}) => api.get('/v1/fleet/trailers', { params }).then(unwrap).catch(handleErr),
    create:   (data) => api.post('/v1/fleet/trailers', data).then(unwrap).catch(handleErr),
    update:   (id, data) => api.put(`/v1/fleet/trailers/${id}`, data).then(unwrap).catch(handleErr),
    compliance: (id) => api.get(`/v1/fleet/trailers/${id}/compliance`).then(unwrap).catch(handleErr),
    couple:   (id, vehicleId, reason = null) =>
      api.post(`/v1/fleet/trailers/${id}/couple`, { vehicle_id: vehicleId, reason }).then(unwrap).catch(handleErr),
    uncouple: (id, reason = null) =>
      api.post(`/v1/fleet/trailers/${id}/uncouple`, { reason }).then(unwrap).catch(handleErr),
    history:  (params = {}) => api.get('/v1/fleet/trailers/history', { params }).then(unwrap).catch(handleErr),
  },

  urea: {
    record: (vehicleId, data) => api.post(`/v1/fleet/vehicles/${vehicleId}/urea`, data).then(unwrap).catch(handleErr),
  },

  tyres: {
    forVehicle: (vehicleId) => api.get(`/v1/fleet/vehicles/${vehicleId}/tyres`).then(unwrap).catch(handleErr),
    fit:     (data) => api.post('/v1/fleet/tyres/fit', data).then(unwrap).catch(handleErr),
    inspect: (id, data) => api.put(`/v1/fleet/tyres/${id}/inspect`, data).then(unwrap).catch(handleErr),
    remove:  (id, data) => api.put(`/v1/fleet/tyres/${id}/remove`, data).then(unwrap).catch(handleErr),

    // ── The casing register (T-36/37/38) ──────────────────────────────
    // The fitment calls above are about an axle; these are about the casing,
    // which outlives every truck it is fitted to.
    register:  (params = {}) => api.get('/v1/fleet/tyres', { params }).then(unwrap).catch(handleErr),
    create:    (data) => api.post('/v1/fleet/tyres', data).then(unwrap).catch(handleErr),
    update:    (id, data) => api.put(`/v1/fleet/tyres/${id}`, data).then(unwrap).catch(handleErr),
    economics: (id) => api.get(`/v1/fleet/tyres/${id}/economics`).then(unwrap).catch(handleErr),
    // One act, not four: both fitments close and reopen at one odometer.
    rotate:    (firstFitmentId, secondFitmentId, odometer = null) =>
      api.post('/v1/fleet/tyres/rotate', {
        first_fitment_id: firstFitmentId, second_fitment_id: secondFitmentId, odometer,
      }).then(unwrap).catch(handleErr),
    retread:   (id, data) => api.post(`/v1/fleet/tyres/${id}/retread`, data).then(unwrap).catch(handleErr),
    scrap:     (id, reason) => api.post(`/v1/fleet/tyres/${id}/scrap`, { reason }).then(unwrap).catch(handleErr),
  },

  // Developer 3's contract, over HTTP. The in-process
  // FleetService::getTripOperatingCosts() is the primary surface.
  trips: {
    operatingCosts: (tripId) => api.get(`/v1/fleet/trips/${tripId}/operating-costs`).then(unwrap).catch(handleErr),
  },

  maintenance: {
    board:  (params = {}) => api.get('/v1/fleet/maintenance/job-cards', { params }).then(unwrap).catch(handleErr),
    open:   (data) => api.post('/v1/fleet/maintenance/job-cards', data).then(unwrap).catch(handleErr),
    update: (id, data) => api.put(`/v1/fleet/maintenance/job-cards/${id}`, data).then(unwrap).catch(handleErr),
    close:  (id, data) => api.put(`/v1/fleet/maintenance/job-cards/${id}/close`, data).then(unwrap).catch(handleErr),
  },
}

/**
 * The grid's filter tiles.
 *
 * These are TELEMETRY-derived presentation states (moving / idle / offline), not
 * the vehicle asset state machine in VEHICLE_STATUS_LABELS below. Two different
 * vocabularies on purpose: one answers "what is this truck doing right now",
 * the other "what may be done with it".
 *
 * Dispatch has since landed, so ALLOCATED and IN_TRANSIT are real asset states —
 * but they are not filter tiles here until FleetService::grid() can filter on
 * them. Adding the tile first would give a user a filter that returns nothing.
 */
export const FLEET_STATES = [
  { value: '',                   label: 'All' },
  { value: 'moving',             label: 'Moving' },
  { value: 'idle',               label: 'Idle' },
  { value: 'offline',            label: 'Offline' },
  { value: 'maintenance',        label: 'Maintenance' },
  { value: 'compliance_blocked', label: 'Compliance blocked' },
  { value: 'unmonitored',        label: 'Unmonitored' },
  { value: 'retired',            label: 'Retired' },
]

/**
 * The vehicle asset state machine, as ruled 2026-09-19 — Fleet is its sole
 * authority. See docs/transport/STOS-PROCESS-FLOW-AND-OWNERSHIP.md §4.
 *
 * Labelled here so a screen never shows a user `UNDER_MAINTENANCE`. The wire
 * value is uppercase because it crosses a module boundary; what a person reads
 * is a sentence.
 */
export const VEHICLE_STATUS_LABELS = {
  AVAILABLE:          'Available',
  ALLOCATED:          'Allocated to a trip',
  IN_TRANSIT:         'In transit',
  UNDER_MAINTENANCE:  'In the workshop',
  COMPLIANCE_BLOCKED: 'Compliance blocked',
  IDLE:               'Idle',
  BREAKDOWN:          'Broken down',
  RETIRED:            'Retired',
}

export const vehicleStatusLabel = (status) =>
  VEHICLE_STATUS_LABELS[status] || String(status || '').replace(/_/g, ' ').toLowerCase()

/** Traffic light. One definition, so no two screens disagree about red. */
export const TONES = {
  green: { dot: 'var(--color-success-500, #10b981)', label: 'Healthy' },
  amber: { dot: 'var(--color-warning-500, #f59e0b)', label: 'Attention' },
  red:   { dot: 'var(--color-danger-500, #ef4444)',  label: 'Blocked' },
}

export const toneOf = (tone) => TONES[tone] || TONES.green

/** GPS signal health — three states, because "stale" is not "dead". */
export const SIGNALS = {
  active:   { label: 'GPS active',   tone: 'green' },
  degraded: { label: 'GPS degraded', tone: 'amber' },
  offline:  { label: 'GPS offline',  tone: 'red' },
}

/** The five statutory papers, in the order the compliance tab lists them. */
export const EXPIRY_DOCUMENTS = [
  { field: 'registration_expiry', label: 'Registration (RC)' },
  { field: 'insurance_expiry',    label: 'Insurance' },
  { field: 'fitness_expiry',      label: 'Fitness certificate' },
  { field: 'permit_expiry',       label: 'Permit' },
  { field: 'puc_expiry',          label: 'Pollution (PUC)' },
]

/** A document's state maps onto the same traffic light as everything else. */
export const DOC_STATE_TONE = {
  valid: 'green', expiring: 'amber', expired: 'red', unknown: 'amber',
}

export const LICENCE_CLASSES = [
  { value: 'LMV',   label: 'LMV — light' },
  { value: 'HMV',   label: 'HMV — heavy' },
  { value: 'HTV',   label: 'HTV — transport' },
  { value: 'HAZ',   label: 'HAZ — hazardous' },
  { value: 'OTHER', label: 'Other' },
]

/**
 * Mirrors `DriverProfile::STATUSES` — UPPERCASE since T-42, when the last
 * lowercase enum in the module was converted.
 *
 * ON_LEAVE and INACTIVE are separate on purpose: "away until the 14th" and "no
 * longer works here" are different facts, and a roster that merges them either
 * chases somebody who left or writes off somebody who is back on Monday.
 */
/**
 * Mirrors `VehicleLiveStatus::GENERATOR_STATES` — UPPERCASE since T-06.
 *
 * Richer than STOS-API's `ON | OFF | UNKNOWN` on purpose: a genset in FAULT is
 * not one somebody switched OFF, and the person fixing it needs to know which.
 * UNKNOWN arrives as null — "the device did not say" is its own state.
 */
export const GENSET_STATE_LABELS = {
  OFF: 'Off', ON: 'Running', STANDBY: 'On standby power', FAULT: 'Faulted',
}

/** States in which the genset is NOT cooling the load. */
export const GENSET_NOT_COOLING = ['OFF', 'FAULT']

/**
 * Mirrors `TyreMaster::STATUSES`. FITTED and RETREADED are written by fitting
 * and by the retread action, so the register never offers them as choices.
 */
export const TYRE_MASTER_STATUS_LABELS = {
  IN_STOCK: 'In the store',
  FITTED: 'Fitted',
  RETREADED: 'Back from retread',
  SCRAPPED: 'Scrapped',
}

/** Mirrors `TyreFitment::STATUSES` — what a fitment row ended as. */
export const TYRE_FITMENT_STATUS_LABELS = {
  IN_STOCK: 'Back to stock',
  FITTED: 'Fitted',
  REMOVED: 'Removed',
  RETREADED: 'Sent to retread',
  SCRAPPED: 'Scrapped',
}

/**
 * What each `wear.basis` from the forecast means, in words a workshop reads.
 * The service names thin evidence rather than guessing, and the screen has to
 * say which case it is instead of showing a blank.
 */
export const TYRE_WEAR_BASIS = {
  measured: null,
  no_measurements: 'No tread depth recorded yet — inspect it to start a forecast.',
  one_measurement: 'Only one tread reading so far. A second one gives a wear rate.',
  no_wear_measured: 'The readings show no wear, usually because it was retreaded between them.',
  no_scrap_depth_set: 'Set the scrap depth on this casing to get a replacement point.',
  at_or_below_floor: 'At or below its scrap depth — replace it.',
  beyond_horizon: 'Wearing slowly enough that a replacement point would be a guess.',
}

/** Mirrors `Trailer::TYPES`. */
export const TRAILER_TYPES = [
  { value: 'flatbed',  label: 'Flatbed' },
  { value: 'skeletal', label: 'Container skeletal' },
  { value: 'tipper',   label: 'Tipper' },
  { value: 'tanker',   label: 'Tanker' },
  { value: 'reefer',   label: 'Reefer' },
  { value: 'curtain',  label: 'Curtain side' },
  { value: 'lowbed',   label: 'Low bed' },
  { value: 'other',    label: 'Other' },
]

export const TRAILER_TYPE_LABELS = Object.fromEntries(TRAILER_TYPES.map((t) => [t.value, t.label]))

/**
 * Mirrors `Trailer::STATUSES`. Only three are offered: COUPLED is written by
 * coupling and COMPLIANCE_BLOCKED is derived from the document dates, so a box
 * offering either would be one that always errors.
 */
export const TRAILER_STATUS_LABELS = {
  AVAILABLE: 'In the yard',
  COUPLED: 'Coupled',
  UNDER_MAINTENANCE: 'Under repair',
  COMPLIANCE_BLOCKED: 'Papers lapsed',
  RETIRED: 'Retired',
}

export const SETTABLE_TRAILER_STATUSES = [
  { value: 'AVAILABLE', label: 'In the yard' },
  { value: 'UNDER_MAINTENANCE', label: 'Under repair' },
  { value: 'RETIRED', label: 'Retired' },
]

export const DRIVER_STATUSES = [
  { value: 'AVAILABLE', label: 'Available' },
  { value: 'ON_TRIP',   label: 'On trip', systemOnly: true },
  { value: 'SUSPENDED', label: 'Suspended' },
  { value: 'ON_LEAVE',  label: 'On leave' },
  { value: 'INACTIVE',  label: 'No longer with us' },
]

/**
 * What a person may choose.
 *
 * ON_TRIP is written by dispatch when a trip takes the driver and cleared when
 * it releases them — the server refuses it here, so offering it would be a box
 * that always errors.
 */
export const SETTABLE_DRIVER_STATUSES = DRIVER_STATUSES.filter((s) => !s.systemOnly)

/** Label by value, so no screen has to un-snake_case a status by hand. */
export const DRIVER_STATUS_LABELS = Object.fromEntries(
  DRIVER_STATUSES.map((s) => [s.value, s.label])
)

export const TYRE_POSITIONS = [
  'front_left', 'front_right',
  'rear_inner_left', 'rear_outer_left',
  'rear_inner_right', 'rear_outer_right',
  'trailer_left', 'trailer_right',
  'spare',
]

export const TYRE_POSITION_LABEL = (p) => String(p || '').replace(/_/g, ' ')

/**
 * T-01 — mirrors `Vehicle::FUEL_TYPES`. The blank first option is deliberate:
 * fuel type is nullable, and forcing "diesel" on a vehicle nobody recorded
 * would be a guess written into the register as a fact.
 */
export const FUEL_TYPES = [
  { value: '',         label: 'Not recorded' },
  { value: 'diesel',   label: 'Diesel' },
  { value: 'petrol',   label: 'Petrol' },
  { value: 'cng',      label: 'CNG' },
  { value: 'lng',      label: 'LNG' },
  { value: 'electric', label: 'Electric' },
  { value: 'hybrid',   label: 'Hybrid' },
]

/**
 * Mirrors `Genset::STATUSES` — UPPERCASE since T-58.
 *
 * Database enums and state-machine states are UPPERCASE; API blocker codes and
 * machine reasons are lowercase snake_case (spec 12.S11). These are the former,
 * so the value is what the column holds and only the label is for reading.
 */
export const GENSET_STATUSES = [
  { value: 'IDLE',           label: 'In the yard' },
  { value: 'ACTIVE',         label: 'In service' },
  { value: 'IN_MAINTENANCE', label: 'Under repair' },
  { value: 'RETIRED',        label: 'Retired' },
]

export const JOB_STATUSES = [
  { value: 'OPEN',           label: 'Open',           open: true },
  { value: 'IN_PROGRESS',    label: 'In progress',    open: true },
  { value: 'AWAITING_PARTS', label: 'Awaiting parts', open: true },
  // T-32 — the work is done but nobody has signed it off yet. This is exactly
  // the window in which a vehicle gets taken, so both still hold it.
  { value: 'TESTING',        label: 'Road testing',   open: true },
  { value: 'QC',             label: 'With QC',        open: true },
  { value: 'COMPLETED',      label: 'Completed' },
  { value: 'CANCELLED',      label: 'Cancelled' },
]

/**
 * Derived from the flag, not a positional slice.
 *
 * This was `JOB_STATUSES.slice(0, 3)`, which silently meant the wrong thing the
 * moment a status was inserted before `completed` — a vehicle in QC would have
 * read as released.
 */
export const OPEN_JOB_STATUSES = JOB_STATUSES.filter((s) => s.open)

/** One definition of "still in the workshop", so no screen disagrees. */
export const isJobOpen = (status) => OPEN_JOB_STATUSES.some((s) => s.value === status)

/** T-31 — what QC actually said. CRITICAL_FAIL is not just a louder FAIL: it
 *  keeps holding the vehicle after this card closes, until a later QC clears it. */
export const QC_RESULTS = [
  { value: 'PASS',          label: 'Pass — safe to release' },
  { value: 'FAIL',          label: 'Fail — rework needed' },
  { value: 'CRITICAL_FAIL', label: 'Critical fail — vehicle condemned' },
]

/**
 * The Next-Action Engine's link resolver.
 *
 * The backend answers with an action key ("passport#telemetry") rather than a
 * URL, so the server never has to know the frontend's routing. This is the one
 * place that translates.
 */
export const nextActionTo = (vehicleId, action = 'passport') => {
  const [, hash] = String(action).split('#')
  return `/app/transport/fleet/vehicles/${vehicleId}${hash ? `#${hash}` : ''}`
}

export const VEHICLE_TYPE_LABELS = {
  truck: 'Truck', trailer: 'Trailer', tipper: 'Tipper',
  tanker: 'Tanker', reefer: 'Reefer', lcv: 'LCV', other: 'Other',
}

export const VEHICLE_TYPE_OPTIONS = Object.entries(VEHICLE_TYPE_LABELS)
  .map(([value, label]) => ({ value, label }))

export const fmtMoney = (n) =>
  `₹${Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

export const fmtWhen = (value) => {
  if (!value) return '—'
  const d = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })
}

export const fmtAgo = (value) => {
  if (!value) return 'never'
  const d = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return String(value)

  const mins = Math.round((Date.now() - d.getTime()) / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins} min ago`
  const hrs = Math.round(mins / 60)
  if (hrs < 24) return `${hrs} h ago`
  return `${Math.round(hrs / 24)} d ago`
}
