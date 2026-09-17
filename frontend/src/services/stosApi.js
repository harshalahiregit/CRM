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
    saveProfile: (source, personId, data) =>
      api.put(`/v1/fleet/drivers/${source}/${personId}`, data).then(unwrap).catch(handleErr),
    // The REGULAR assignment the allocation engine reads. Pass null to clear.
    assign: (source, personId, vehicleId) =>
      api.put(`/v1/fleet/drivers/${source}/${personId}/assign`, { vehicle_id: vehicleId }).then(unwrap).catch(handleErr),
  },

  urea: {
    record: (vehicleId, data) => api.post(`/v1/fleet/vehicles/${vehicleId}/urea`, data).then(unwrap).catch(handleErr),
  },

  tyres: {
    forVehicle: (vehicleId) => api.get(`/v1/fleet/vehicles/${vehicleId}/tyres`).then(unwrap).catch(handleErr),
    fit:     (data) => api.post('/v1/fleet/tyres/fit', data).then(unwrap).catch(handleErr),
    inspect: (id, data) => api.put(`/v1/fleet/tyres/${id}/inspect`, data).then(unwrap).catch(handleErr),
    remove:  (id, data) => api.put(`/v1/fleet/tyres/${id}/remove`, data).then(unwrap).catch(handleErr),
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
 * "Allocated" and "In Transit" are NOT here. Those are trip facts and Dispatch
 * (Developer 1) owns trips — deriving them from telemetry would report a yard
 * shunt as a delivery. They arrive when Dispatch does.
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

export const DRIVER_STATUSES = [
  { value: 'available', label: 'Available' },
  { value: 'on_trip',   label: 'On trip' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'inactive',  label: 'Inactive' },
]

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

export const JOB_STATUSES = [
  { value: 'open',           label: 'Open',           open: true },
  { value: 'in_progress',    label: 'In progress',    open: true },
  { value: 'awaiting_parts', label: 'Awaiting parts', open: true },
  // T-32 — the work is done but nobody has signed it off yet. This is exactly
  // the window in which a vehicle gets taken, so both still hold it.
  { value: 'testing',        label: 'Road testing',   open: true },
  { value: 'qc',             label: 'With QC',        open: true },
  { value: 'completed',      label: 'Completed' },
  { value: 'cancelled',      label: 'Cancelled' },
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
