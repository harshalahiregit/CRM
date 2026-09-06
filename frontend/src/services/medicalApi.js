/**
 * Medical module API.
 *
 * Four callers, one file: the doctor portal, the admin quality check (TPV and
 * Purchase), the vendor portals, and the public verification page. They are
 * grouped by WHO is calling rather than by module, because that is how the
 * screens are built — a doctor works across both vendor sides, and a reviewer
 * works within one.
 *
 * Every call ends in `.catch(handleErr)`. Without it an axios rejection reaches
 * the toast as its own message — "Request failed with status code 422" — which
 * says a field was refused but not which one, and Laravel's per-field `errors`
 * object is thrown away. handleErr turns that into "Email — this email already
 * has a login", the way the rest of the app already reports failures.
 */

import api from '@/lib/api'
import pvApi from '@/lib/purchaseVendorApi'
import { handleErr } from '@/services/apiError'

/**
 * Which axios client a PORTAL call goes out on.
 *
 * The two vendor portals do not share an identity. A TPV vendor holds a normal
 * user session on the default client; a Purchase vendor holds a PurchaseVendor
 * token in its own storage key on its own instance. Sending a Purchase portal
 * request down the default client means sending it with no token at all — the
 * server answers 401, the default client reads that as "the session has ended"
 * and redirects to /auth/login. That is what made the Purchase dashboard appear
 * for a moment and then bounce back to the login page.
 *
 * The base path already says which portal we are in, so it can say which client
 * to use too.
 */
const clientFor = (base = '') => (String(base).startsWith('/portal/purchase') ? pvApi : api)

/**
 * The same normalisation, for a request that asked for a blob.
 *
 * When a PDF or template download fails, axios still hands back the error body
 * as a Blob because that is what responseType said to expect — so `data.errors`
 * is undefined and the reason is invisible. Reading the blob back into JSON
 * first means a refused download explains itself like any other call.
 */
const handleBlobErr = async (err) => {
  const body = err?.response?.data
  if (body instanceof Blob) {
    try {
      err.response.data = JSON.parse(await body.text())
    } catch {
      // Not JSON (a real PDF, or an HTML error page) — leave it; handleErr
      // still reports the status.
    }
  }
  return handleErr(err)
}

// The shared axios instance defaults to application/json; Content-Type must be
// cleared for the browser to set the multipart boundary itself.
const upload = (url, formData, client = api) =>
  client.post(url, formData, { headers: { 'Content-Type': undefined } })
    .then(r => r.data).catch(handleErr)

/** Build a FormData from a plain object, skipping empties and unrolling arrays. */
const toFormData = (payload = {}) => {
  const fd = new FormData()
  Object.entries(payload).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return
    if (Array.isArray(value)) {
      value.forEach(item => {
        if (item instanceof File) fd.append(`${key}[]`, item)
        else fd.append(`${key}[]`, typeof item === 'object' ? JSON.stringify(item) : item)
      })
      return
    }
    if (value instanceof File) { fd.append(key, value); return }
    if (typeof value === 'object') { fd.append(key, JSON.stringify(value)); return }
    fd.append(key, value)
  })
  return fd
}

/** A file download that the browser saves rather than renders. */
const download = async (url, filename, params = {}, client = api) => {
  const res = await client.get(url, { params, responseType: 'blob' }).catch(handleBlobErr)
  const href = window.URL.createObjectURL(new Blob([res.data]))
  const link = document.createElement('a')
  link.href = href
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(href)
}

/** A PDF opened in a new tab instead of saved. */
/**
 * Open a generated PDF in a new tab.
 *
 * The tab is opened BEFORE the request, while the click that asked for it is
 * still the reason this code is running. Opening it after the `await` — which
 * is what this did — puts it outside that window, and every popup blocker drops
 * such a call silently: no tab, no error, no console warning. The button simply
 * appears not to work, which is exactly how it was reported.
 *
 * `noopener` is dropped deliberately: with it the browser returns null by spec
 * and there is no handle left to point at the blob. The document being opened
 * is a blob this page just built, so there is nothing to be protected from.
 *
 * Returns whether a tab was actually obtained, and the URL either way, so a
 * caller can offer a link the reader clicks themselves when the blocker wins —
 * a real click always works.
 */
const openPdf = async (url, client = api) => {
  const tab = window.open('', '_blank')

  try {
    const res = await client.get(url, { responseType: 'blob' }).catch(handleBlobErr)
    const href = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    // Give the tab time to take the blob before it is released.
    setTimeout(() => window.URL.revokeObjectURL(href), 60_000)

    if (tab && !tab.closed) {
      tab.location = href
      return { opened: true, href }
    }

    return { opened: false, href }
  } catch (e) {
    // Never leave a blank tab sitting there after a failed request.
    try { tab?.close() } catch { /* already gone */ }
    throw e
  }
}

export const medicalApi = {
  /* ── Doctor portal — the Internal Medical Flow ────────────────────── */
  doctor: {
    me:            ()        => api.get('/doctor/me').then(r => r.data?.data ?? r.data).catch(handleErr),
    updateProfile: (data)    => api.put('/doctor/me', data).then(r => r.data).catch(handleErr),
    summary:       ()        => api.get('/doctor/summary').then(r => r.data?.data ?? r.data).catch(handleErr),

    // Every call carries the side the doctor is working on: 'tpv' | 'purchase'.
    // These return { data, meta } — meta says how many matched in total, so a
    // list can admit what it is not showing instead of silently stopping.
    vendors: (module, params = {}) =>
      api.get(`/doctor/${module}/vendors`, { params }).then(r => r.data).catch(handleErr),
    workers: (module, params = {}) =>
      api.get(`/doctor/${module}/workers`, { params }).then(r => r.data).catch(handleErr),
    worker: (module, id) =>
      api.get(`/doctor/${module}/workers/${id}`).then(r => r.data?.data ?? r.data).catch(handleErr),

    // The examination form. Sent as multipart because it can carry a report
    // file alongside the signature and camera capture.
    examine: (module, workerId, payload) =>
      upload(`/doctor/${module}/workers/${workerId}/examination`, toFormData(payload)),

    examinations: (module, params = {}) =>
      api.get(`/doctor/${module}/examinations`, { params }).then(r => r.data?.data ?? r.data).catch(handleErr),
    examination: (module, id) =>
      api.get(`/doctor/${module}/examinations/${id}`).then(r => r.data?.data ?? r.data).catch(handleErr),
    certificate: (module, id) =>
      openPdf(`/doctor/${module}/examinations/${id}/certificate`),

    /* ── Everyone who is not a vendor worker ─────────────────────────── */
    // `audience` is 'internal' | 'client' | 'visitor'. These have no vendor
    // above them, so the flow is one flat searchable list rather than
    // vendor-then-worker — same shape either way for the picker that drives it.
    people: (audience, params = {}) =>
      api.get(`/doctor/${audience}/people`, { params }).then(r => r.data).catch(handleErr),
    person: (audience, id) =>
      api.get(`/doctor/${audience}/people/${id}`).then(r => r.data?.data ?? r.data).catch(handleErr),
    examinePerson: (audience, id, payload) =>
      upload(`/doctor/${audience}/people/${id}/examination`, toFormData(payload)),
    personCertificate: (audience, medicalId) =>
      openPdf(`/doctor/${audience}/records/${medicalId}/certificate`),
    // A walk-in the system has never met, registered so they can be examined.
    createVisitor: (data) =>
      api.post('/doctor/visitors', data).then(r => r.data).catch(handleErr),

    // What a ticked group of people have IN COMMON. POST because the set of
    // ids IS the request, and a morning's session can be a hundred of them.
    // `scope` is a vendor side or an audience — the URL shape is the same.
    groupFindings: (scope, ids) =>
      api.post(`/doctor/${scope}/group-findings`, { ids })
        .then(r => r.data?.data ?? r.data).catch(handleErr),
  },

  /* ── The general register, for an admin ───────────────────────────── */
  // Examinations of internal staff, client contacts and site visitors. The
  // doctor portal has been writing these all along; nothing could read them
  // back until now, so the audience was recorded and invisible.
  general: {
    list:   (params = {}) => api.get('/medical/general', { params }).then(r => r.data).catch(handleErr),
    report: (params = {}) => api.get('/medical/general/report', { params }).then(r => r.data).catch(handleErr),
    one:    (id) => api.get(`/medical/general/${id}`).then(r => r.data?.data ?? r.data).catch(handleErr),
    certificate: (id) => openPdf(`/medical/general/${id}/certificate`),
  },

  /* ── Admin register + quality check ───────────────────────────────── */
  // `module` selects the register: 'tpv' | 'purchase'. The two APIs are route
  // for route identical, which is what lets one screen serve both.
  admin: {
    list:   (module, params = {}) => api.get(`/${module}/medical`, { params }).then(r => r.data).catch(handleErr),
    get:    (module, id)          => api.get(`/${module}/medical/${id}`).then(r => r.data?.data ?? r.data).catch(handleErr),
    decide: (module, id, data)    => api.post(`/${module}/medical/${id}/decide`, data).then(r => r.data).catch(handleErr),
    comment: (module, id, payload) =>
      upload(`/${module}/medical/${id}/comment`, toFormData(payload)),

    certificate: (module, id) => openPdf(`/${module}/medical/${id}/certificate`),
    document:    (module, id) => openPdf(`/${module}/medical/${id}/document`),

    // External intake.
    storeExternal: (module, workerId, payload) =>
      upload(
        module === 'tpv'
          ? `/tpv/workers/${workerId}/medical/external`
          : `/purchase/workforce/workers/${workerId}/medical/external`,
        toFormData(payload),
      ),
    template:   (module, format = 'csv') =>
      download(`/${module}/medical/template`, `medical-certificates-template.${format}`, { format }),
    bulkUpload: (module, payload) => upload(`/${module}/medical/bulk`, toFormData(payload)),
    batches:    (module)          => api.get(`/${module}/medical/batches`).then(r => r.data?.data ?? r.data).catch(handleErr),

    // The Medical report — totals, vendor stats, successes/failures, health
    // ratings — and the vendor table as a spreadsheet.
    report: (module, params = {}) =>
      api.get(`/${module}/medical/report`, { params }).then(r => r.data?.data ?? r.data).catch(handleErr),
    reportExport: (module, params = {}, format = 'csv') =>
      download(`/${module}/medical/report/export`, `medical-report-${module}.${format}`, { ...params, format }),

    workerHistory: (module, workerId) =>
      api.get(
        module === 'tpv'
          ? `/tpv/workers/${workerId}/medical-history`
          : `/purchase/workforce/workers/${workerId}/medical-history`,
      ).then(r => r.data?.data ?? r.data).catch(handleErr),
  },

  /* ── Vendor portal — the External Medical Flow ────────────────────── */
  // `base` is '/portal' for TPV vendors and '/portal/purchase' for Purchase
  // vendors; the two portals expose the same shape under different prefixes.
  portal: {
    list:   (base) => clientFor(base).get(`${base}/medical`).then(r => r.data).catch(handleErr),
    get:    (base, id) => clientFor(base).get(`${base}/medical/${id}`).then(r => r.data?.data ?? r.data).catch(handleErr),

    store: (base, workerId, payload) =>
      upload(`${base}/workers/${workerId}/medical/external`, toFormData(payload), clientFor(base)),

    resubmit: (base, id, payload) => upload(`${base}/medical/${id}/resubmit`, toFormData(payload), clientFor(base)),
    comment:  (base, id, payload) => upload(`${base}/medical/${id}/comment`, toFormData(payload), clientFor(base)),

    template:   (base, format = 'csv') =>
      download(`${base}/medical/template`, `medical-certificates-template.${format}`, { format }, clientFor(base)),
    bulkUpload: (base, payload) => upload(`${base}/medical/bulk`, toFormData(payload), clientFor(base)),
    batches:    (base)          => clientFor(base).get(`${base}/medical/batches`).then(r => r.data?.data ?? r.data).catch(handleErr),

    certificate: (base, id) => openPdf(`${base}/medical/${id}/certificate`, clientFor(base)),
    document:    (base, id) => openPdf(`${base}/medical/${id}/document`, clientFor(base)),
  },

  /* ── Doctor directory (admin) ─────────────────────────────────────── */
  doctors: {
    list:   (params = {}) => api.get('/medical/doctors', { params }).then(r => r.data?.data ?? r.data).catch(handleErr),
    create: (data)        => api.post('/medical/doctors', data).then(r => r.data).catch(handleErr),
    update: (id, data)    => api.put(`/medical/doctors/${id}`, data).then(r => r.data).catch(handleErr),
    // Returns the new password once — it is only hashed after this.
    resetPassword: (id, password) =>
      api.post(`/medical/doctors/${id}/reset-password`, password ? { password } : {})
        .then(r => r.data).catch(handleErr),
    // Deactivates; an issued certificate must keep naming its author.
    deactivate: (id)      => api.delete(`/medical/doctors/${id}`).then(r => r.data).catch(handleErr),
  },

  /* ── Public verification (no auth — what the QR opens) ─────────────── */
  verify: (certificateNo) =>
    api.get(`/public/medical/verify/${encodeURIComponent(certificateNo)}`).then(r => r.data?.data ?? r.data).catch(handleErr),
}

export default medicalApi
