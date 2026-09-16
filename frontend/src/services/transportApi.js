import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

/**
 * Sangoe Transport OS — every /api/transport/* call.
 *
 * Uses the shared axios instance from @/lib/api, which attaches the bearer
 * token and ends the session only on a genuine auth failure. HR's own client
 * re-implements both and does not inherit fixes to the shared one; Transport
 * does not repeat that.
 *
 * Unwrapping happens here, not in the pages: the API returns the standard
 * { status, message, data } envelope, so each method hands the component the
 * shape it actually needs.
 *
 * Only endpoints with a server behind them. A client method with nothing
 * answering it is a promise the UI cannot keep — so there is still nothing here
 * for viability, billing, collections, settlement or the control room.
 */

/**
 * A 422 from this module is a business refusal carrying a readable message —
 * an advance over its limit, a duplicate cost, a POD already decided. The panel
 * should show that sentence in place rather than treat it as a crash, so these
 * resolve `{ ok: false, message }` instead of throwing. Anything else still
 * throws, because a 500 is not something a user can act on.
 */
const err422 = (e) => {
  const body = e?.response?.data
  if (body && e?.response?.status === 422) {
    return { ok: false, message: body.message, ...(body.data ?? {}) }
  }
  throw e
}

const post422 = (url, body) =>
  api.post(url, body).then((r) => ({ ok: true, ...(r.data?.data ?? {}) })).catch(err422)

/* ── Orders (SNG-TRN-006) ─────────────────────────────────────────────── */

export const transportOrderApi = {
  /** Paginated list. Returns the paginator so the page can read meta. */
  list: (params = {}) =>
    api.get('/transport/orders', { params }).then((r) => r.data?.data ?? { data: [] }).catch(handleErr),

  /** Counts per status, for the filter chips. */
  statusCounts: () =>
    api.get('/transport/orders/status-counts').then((r) => r.data?.data ?? {}).catch(handleErr),

  /** Detail returns { order, audit } — the record and its trail. */
  get: (id) =>
    api.get(`/transport/orders/${id}`).then((r) => r.data?.data ?? null).catch(handleErr),

  create: (payload) =>
    api.post('/transport/orders', payload).then((r) => r.data?.data).catch(handleErr),

  update: (id, payload) =>
    api.put(`/transport/orders/${id}`, payload).then((r) => r.data?.data).catch(handleErr),

  /** Move through SM-ORD. `reason` is required by the API for a rejection. */
  transition: (id, status, reason = null) =>
    api.patch(`/transport/orders/${id}/status`, { status, reason }).then((r) => r.data?.data).catch(handleErr),
}

/* ── Trips (SNG-TRN-007) ──────────────────────────────────────────────── */

export const transportTripApi = {
  list: (params = {}) =>
    api.get('/transport/trips', { params }).then((r) => r.data?.data ?? { data: [] }).catch(handleErr),

  statusCounts: () =>
    api.get('/transport/trips/status-counts').then((r) => r.data?.data ?? {}).catch(handleErr),

  /** Detail returns { trip, audit }. */
  get: (id) =>
    api.get(`/transport/trips/${id}`).then((r) => r.data?.data ?? null).catch(handleErr),

  /** Create from an approved order. The API refuses anything else. */
  create: (payload) =>
    api.post('/transport/trips', payload).then((r) => r.data?.data).catch(handleErr),

  update: (id, payload) =>
    api.put(`/transport/trips/${id}`, payload).then((r) => r.data?.data).catch(handleErr),

  /** STT-001 — the only transition this ticket owns. */
  submitForViability: (id) =>
    api.patch(`/transport/trips/${id}/submit-viability`).then((r) => r.data?.data).catch(handleErr),
}

/* ── Allocation (SNG-TRN-009) ─────────────────────────────────────────── */

/**
 * What THIS module's trips say about a vehicle or driver.
 *
 * Deliberately a SEPARATE call from `candidates`, not a field inside it:
 * candidates are built by the eligibility services, which belong to Person 2's
 * allocation scoring, and adding a field there would be a contract change to
 * somebody else's surface. The panel merges the two by resource id.
 *
 * It reports a COMMITMENT, never availability. A resource missing from the
 * result means "no trip of mine is holding it" — NOT "it is free".
 */
export const transportResourceCommitmentApi = {
  all: () =>
    api.get('/transport/resource-commitments')
      .then((r) => r.data?.data ?? { vehicles: {}, drivers: {} })
      .catch(handleErr),
}

export const transportAllocationApi = {
  /**
   * PLN-002/003 — eligible vehicles and drivers for a trip, in one call.
   *
   * `includeIneligible` also returns the rest with their blockers, which is what
   * lets the panel say WHY a vehicle cannot be picked instead of hiding it and
   * leaving the dispatcher with an unexplained empty list (UX §35).
   */
  candidates: (tripId, includeIneligible = false) =>
    api.get(`/transport/trips/${tripId}/candidates`, {
      params: includeIneligible ? { include_ineligible: 1 } : {},
    }).then((r) => r.data?.data ?? { vehicles: [], drivers: [] }).catch(handleErr),

  /**
   * API-004. Vehicle and/or driver — the API accepts either or both.
   *
   * A 422 is a VERDICT, not a transport error: its body carries the same
   * eligibility shape as a success. handleErr is deliberately not used here, so
   * the caller can read that body instead of only a message.
   */
  assign: (tripId, payload) =>
    api.post(`/transport/trips/${tripId}/assign`, payload)
      .then((r) => ({ ok: true, ...(r.data?.data ?? {}) }))
      .catch((e) => {
        const body = e?.response?.data
        if (body && e?.response?.status === 422) {
          return { ok: false, message: body.message, eligibility: body.eligibility, data: body.data }
        }
        throw e
      }),

  /** Frees the vehicle and driver and reverts the trip to approved. */
  release: (tripId, reason = null) =>
    api.delete(`/transport/trips/${tripId}/assign`, { data: { reason } })
      .then((r) => r.data?.data).catch(handleErr),
}

/* ── Vehicles (SNG-TRN-003) ───────────────────────────────────────────── */

export const transportVehicleApi = {
  list: (params = {}) =>
    api.get('/transport/vehicles', { params }).then((r) => r.data?.data ?? { data: [] }).catch(handleErr),

  statusCounts: () =>
    api.get('/transport/vehicles/status-counts').then((r) => r.data?.data ?? {}).catch(handleErr),

  /** Detail returns { vehicle, documents, eligibility, transitions, audit }. */
  get: (id) =>
    api.get(`/transport/vehicles/${id}`).then((r) => r.data?.data ?? null).catch(handleErr),

  create: (payload) =>
    api.post('/transport/vehicles', payload).then((r) => r.data?.data).catch(handleErr),

  update: (id, payload) =>
    api.put(`/transport/vehicles/${id}`, payload).then((r) => r.data?.data).catch(handleErr),

  /** FLEET §8 — status is a business event, never an edited field. */
  transition: (id, status, reason = null) =>
    api.patch(`/transport/vehicles/${id}/status`, { status, reason }).then((r) => r.data?.data).catch(handleErr),

  addDocument: (id, payload) =>
    api.post(`/transport/vehicles/${id}/documents`, payload).then((r) => r.data?.data).catch(handleErr),

  /** STOS-DOC §26 — supersede and re-issue, never overwrite. */
  renewDocument: (id, documentId, payload) =>
    api.post(`/transport/vehicles/${id}/documents/${documentId}/renew`, payload).then((r) => r.data?.data).catch(handleErr),

  /** Owner/Admin only. FLEET §7's normal end of life is Retire or Sold. */
  remove: (id, reason = null) =>
    api.delete(`/transport/vehicles/${id}`, { data: { reason } }).then((r) => r.data?.data).catch(handleErr),
}

/* ── Drivers (SNG-TRN-004) ────────────────────────────────────────────── */

export const transportDriverApi = {
  list: (params = {}) =>
    api.get('/transport/drivers', { params }).then((r) => r.data?.data ?? { data: [] }).catch(handleErr),

  /** Returns { status: {...}, availability: {...} } — a driver has two axes. */
  statusCounts: () =>
    api.get('/transport/drivers/status-counts').then((r) => r.data?.data ?? { status: {}, availability: {} }).catch(handleErr),

  get: (id) =>
    api.get(`/transport/drivers/${id}`).then((r) => r.data?.data ?? null).catch(handleErr),

  create: (payload) =>
    api.post('/transport/drivers', payload).then((r) => r.data?.data).catch(handleErr),

  update: (id, payload) =>
    api.put(`/transport/drivers/${id}`, payload).then((r) => r.data?.data).catch(handleErr),

  /** One axis at a time: 'status' (lifecycle) or 'availability' (operational). */
  transition: (id, axis, value, reason = null) =>
    api.patch(`/transport/drivers/${id}/status`, { axis, value, reason }).then((r) => r.data?.data).catch(handleErr),

  addDocument: (id, payload) =>
    api.post(`/transport/drivers/${id}/documents`, payload).then((r) => r.data?.data).catch(handleErr),

  renewDocument: (id, documentId, payload) =>
    api.post(`/transport/drivers/${id}/documents/${documentId}/renew`, payload).then((r) => r.data?.data).catch(handleErr),

  /** Owner/Admin only. Deactivating is usually what you want instead. */
  remove: (id, reason = null) =>
    api.delete(`/transport/drivers/${id}`, { data: { reason } }).then((r) => r.data?.data).catch(handleErr),
}

/**
 * Pre-trip checks — SNG-TRN-010.
 *
 * Four calls against three paths. Only POST .../prechecks is specified by the
 * package (Step 5's API sheet); the rest have no registry row at all, because
 * Step 11's LOCKED API registry carries no pre-trip endpoint — defect D-15.
 *
 * A 422 from this module is a VERDICT, not a transport error: BRW-046/BRW-052,
 * OPS §30 and CMP §159 all treat a dispatch block as an answer with reasons, and
 * the refusal body carries the same readiness shape a success does. So the
 * refusing calls do NOT go through handleErr — the caller needs that body to
 * render why, per UX §35.
 */
export const transportPretripApi = {
  /** Derived readiness. A pure read: it never generates. */
  readiness: (tripId) =>
    api.get(`/transport/trips/${tripId}/prechecks`).then((r) => r.data?.data ?? null).catch(handleErr),

  /** Step 5's path. Reconciles the checklist, then evaluates every row. */
  generate: (tripId) =>
    api.post(`/transport/trips/${tripId}/prechecks`)
      .then((r) => ({ ok: true, readiness: r.data?.data ?? null }))
      .catch((e) => {
        const body = e?.response?.data
        if (body && e?.response?.status === 422) {
          return { ok: false, message: body.message, readiness: body.data }
        }
        throw e
      }),

  /**
   * Confirm ONE check — the ticket's acceptance criterion, per item.
   * Only a remark is accepted; a result is calculated, never entered.
   */
  complete: (tripId, checkId, remarks = null) =>
    api.patch(`/transport/trips/${tripId}/prechecks/${checkId}`, { remarks })
      .then((r) => ({ ok: true, ...(r.data?.data ?? {}) }))
      .catch((e) => {
        const body = e?.response?.data
        if (body && e?.response?.status === 422) {
          return { ok: false, message: body.message, readiness: body.data }
        }
        throw e
      }),

  /**
   * The gate — allocated → pretrip_ok. NOT dispatch: the response carries
   * `dispatched: false`, and pretrip_ok → dispatched belongs to no ticket (D-18).
   */
  pass: (tripId) =>
    api.patch(`/transport/trips/${tripId}/pass-pretrip`)
      .then((r) => ({ ok: true, ...(r.data?.data ?? {}) }))
      .catch((e) => {
        const body = e?.response?.data
        if (body && e?.response?.status === 422) {
          return { ok: false, message: body.message, readiness: body.data }
        }
        throw e
      }),
}

/* ── Consignments (STOS-CTD §8) ───────────────────────────────────────── */

/**
 * The commercial shipment. Six endpoints, staff-only — there is deliberately no
 * customer-facing read until D-46's scope narrowing exists.
 *
 * Note there is no `status` filter and no status field to render: STOS-CTD §11
 * puts consignment status in a lifecycle engine that is not built (D-44), and
 * the API REFUSES a status parameter rather than accepting and ignoring one.
 */
export const transportConsignmentApi = {
  list: (params = {}) =>
    api.get('/transport/consignments', { params }).then((r) => r.data?.data ?? { data: [] }).catch(handleErr),

  get: (id) =>
    api.get(`/transport/consignments/${id}`).then((r) => r.data?.data ?? null).catch(handleErr),

  /** CTD-003 — every consignment on one order. */
  forOrder: (orderId) =>
    api.get(`/transport/orders/${orderId}/consignments`).then((r) => r.data?.data ?? []).catch(handleErr),

  create: (payload) =>
    api.post('/transport/consignments', payload).then((r) => r.data?.data ?? null).catch(handleErr),

  update: (id, payload) =>
    api.put(`/transport/consignments/${id}`, payload).then((r) => r.data?.data ?? null).catch(handleErr),

  remove: (id) =>
    api.delete(`/transport/consignments/${id}`).then((r) => r.data ?? null).catch(handleErr),
}

/* ── Containers (MDM-008, STOS-CTD §7 and §8) ─────────────────────────── */

/**
 * The physical transport unit, as distinct from the consignment it is on.
 *
 * There is no update and no delete, and that is the API's design rather than an
 * omission here: the container number IS the identity, and §7 requires the
 * association history be maintained. A container leaves a consignment by being
 * DETACHED, which keeps the row and stamps `detached_at`.
 */
export const transportContainerApi = {
  list: (params = {}) =>
    api.get('/transport/containers', { params }).then((r) => r.data?.data ?? { data: [] }).catch(handleErr),

  get: (id) =>
    api.get(`/transport/containers/${id}`).then((r) => r.data?.data ?? null).catch(handleErr),

  /**
   * CTD-001 — resolve a container by number, however it was typed. The server
   * normalises before looking up, so `abcd-123456-7` finds `ABCD1234567`.
   */
  lookup: (number) =>
    api.get('/transport/containers/lookup', { params: { number } })
      .then((r) => r.data?.data ?? null).catch(handleErr),

  /** STOS-CTD §8 — every container on one consignment, current and historical. */
  forConsignment: (consignmentId, activeOnly = false) =>
    api.get(`/transport/consignments/${consignmentId}/containers`, {
      params: activeOnly ? { active_only: 1 } : {},
    }).then((r) => r.data?.data ?? []).catch(handleErr),

  create: (payload) =>
    api.post('/transport/containers', payload).then((r) => r.data?.data ?? null).catch(handleErr),

  attach: (id, consignmentId) =>
    api.post(`/transport/containers/${id}/attach`, { consignment_id: consignmentId })
      .then((r) => r.data?.data ?? null).catch(handleErr),

  detach: (id) =>
    api.post(`/transport/containers/${id}/detach`).then((r) => r.data?.data ?? null).catch(handleErr),
}

/* ── Dispatch (RTM STOS-REQ-OPS-008, FRS TRP-P0-006) ──────────────────── */

/**
 * Dispatch confirmation. No Step 12 ticket owns it (D-18); the owner authorised
 * the scope on 2026-09-10. See DispatchScope on the server.
 *
 * Same refusal contract as pre-trip: a 422 carries the full state in its body,
 * so a block renders as an explanation rather than a toast. BRW-048 wants the
 * exact reason and UX §35 forbids merely showing Blocked, and neither is
 * possible if the client throws the body away.
 */
export const transportDispatchApi = {
  /**
   * The whole picture, without attempting anything: frozen fields, version
   * history, and readiness RE-DERIVED live (BRW-046). A pure read — the server
   * writes nothing, so a panel may poll it safely.
   */
  get: (tripId) =>
    api.get(`/transport/trips/${tripId}/dispatch`).then((r) => r.data?.data ?? null).catch(handleErr),

  /** Release the trip — pretrip_ok → dispatched. Does NOT reach in_transit. */
  confirm: (tripId, fields) =>
    api.patch(`/transport/trips/${tripId}/dispatch`, fields)
      .then((r) => ({ ok: true, ...(r.data?.data ?? {}) }))
      .catch((e) => {
        const body = e?.response?.data
        if (body && e?.response?.status === 422) {
          return { ok: false, message: body.message, ...(body.data ?? {}) }
        }
        throw e
      }),

  /**
   * Change a field frozen at release. A reason is mandatory — TRP-P0-006's
   * "changes create version", and the reason is what the version is FOR.
   */
  amend: (tripId, fields, reason) =>
    api.patch(`/transport/trips/${tripId}/dispatch/amend`, { ...fields, reason })
      .then((r) => ({ ok: true, ...(r.data?.data ?? {}) }))
      .catch((e) => {
        const body = e?.response?.data
        if (body && e?.response?.status === 422) {
          return { ok: false, message: body.message, ...(body.data ?? {}) }
        }
        throw e
      }),
}

/**
 * Trip advances — SNG-TRN-011.
 *
 * `list` returns the rows AND the exposure figures (limit, committed,
 * remaining). The panel never adds those up itself: BR-P0-005 is the server's
 * rule, and a screen computing its own remaining balance would eventually
 * disagree with the refusal the server gives.
 *
 * A refusal here is a 422 carrying a readable message, so request/approve/reject
 * resolve `{ ok: false, message }` rather than throwing — the panel shows the
 * reason in place, the same shape dispatch uses.
 */
export const transportAdvanceApi = {
  list: (tripId) =>
    api.get(`/transport/trips/${tripId}/advances`)
      .then((r) => r.data?.data ?? { advances: [], exposure: null }).catch(handleErr),

  request: (tripId, body) => post422(`/transport/trips/${tripId}/advances`, body),

  approve: (tripId, advanceId, body) =>
    post422(`/transport/trips/${tripId}/advances/${advanceId}/approve`, body),

  reject: (tripId, advanceId, reason) =>
    post422(`/transport/trips/${tripId}/advances/${advanceId}/reject`, { decision_reason: reason }),
}

/**
 * Trip costs — SNG-TRN-012.
 *
 * `total` and `breakdown` come from the server, computed with bcmath over a
 * DECIMAL column. A screen adding JavaScript numbers would drift from the figure
 * SNG-TRN-018 reports as margin, so the panel displays these and never sums.
 *
 * `known_types` is a suggestion list, not a permitted-values list — `cost_type`
 * has no registered vocabulary (D-58), so the picker offers these and still
 * accepts anything typed.
 */
export const transportCostApi = {
  list: (tripId) =>
    api.get(`/transport/trips/${tripId}/costs`)
      .then((r) => r.data?.data ?? { costs: [], total: '0.00', breakdown: {}, known_types: [] })
      .catch(handleErr),

  record: (tripId, body) => post422(`/transport/trips/${tripId}/costs`, body),

  /** A soft delete with a mandatory reason — the row survives for audit. */
  retract: (tripId, costId, reason) =>
    api.delete(`/transport/trips/${tripId}/costs/${costId}`, { data: { reason } })
      .then((r) => ({ ok: true, ...(r.data?.data ?? {}) }))
      .catch(err422),
}

/**
 * Trip documents and POD — SNG-TRN-014, API-008.
 *
 * `list` returns the documents AND the billing verdict, because the rule is
 * "POD required before billable unless approved exception" and the waiver arm is
 * not visible from the document rows at all. A screen inferring billability from
 * the list would get the waiver case wrong every time.
 *
 * `submit` posts multipart — CTR-012's `pod_file` is a real upload, so no
 * JSON content type is set and the browser supplies the boundary.
 */
export const transportPodApi = {
  list: (tripId) =>
    api.get(`/transport/trips/${tripId}/documents`)
      .then((r) => r.data?.data ?? { documents: [], billing: null }).catch(handleErr),

  submit: (tripId, file, fields = {}) => {
    const form = new FormData()
    form.append('file', file)
    Object.entries(fields).forEach(([k, v]) => {
      if (v !== null && v !== undefined && v !== '') form.append(k, v)
    })

    return api.post(`/transport/trips/${tripId}/pod`, form)
      .then((r) => ({ ok: true, ...(r.data?.data ?? {}) }))
      .catch(err422)
  },

  verify: (tripId, documentId) => post422(`/transport/trips/${tripId}/pod/${documentId}/verify`, {}),

  reject: (tripId, documentId, reason) =>
    post422(`/transport/trips/${tripId}/pod/${documentId}/reject`, { reason }),
}

/**
 * What the signed-in user may do — so a screen can hide an action the API would
 * refuse rather than show a button that 403s.
 */
export const transportCapabilityApi = {
  get: () =>
    api.get('/transport/permissions').then((r) => r.data?.data ?? { grants: {}, role: null }).catch(handleErr),
}

export const transportApi = {
  capabilities: transportCapabilityApi,
  advances: transportAdvanceApi,
  costs: transportCostApi,
  pod: transportPodApi,
  orders: transportOrderApi,
  trips: transportTripApi,
  allocation: transportAllocationApi,
  resourceCommitments: transportResourceCommitmentApi,
  pretrip: transportPretripApi,
  dispatch: transportDispatchApi,
  consignments: transportConsignmentApi,
  containers: transportContainerApi,
  vehicles: transportVehicleApi,
  drivers: transportDriverApi,
}

export default transportApi
