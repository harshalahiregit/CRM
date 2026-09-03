/**
 * Medical module API.
 *
 * Four callers, one file: the doctor portal, the admin quality check (TPV and
 * Purchase), the vendor portals, and the public verification page. They are
 * grouped by WHO is calling rather than by module, because that is how the
 * screens are built — a doctor works across both vendor sides, and a reviewer
 * works within one.
 */

import api from '@/lib/api'

// The shared axios instance defaults to application/json; Content-Type must be
// cleared for the browser to set the multipart boundary itself.
const upload = (url, formData) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined } }).then(r => r.data)

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
const download = async (url, filename, params = {}) => {
  const res = await api.get(url, { params, responseType: 'blob' })
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
const openPdf = async (url) => {
  const res = await api.get(url, { responseType: 'blob' })
  const href = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
  window.open(href, '_blank', 'noopener')
  // Give the tab time to take the blob before it is released.
  setTimeout(() => window.URL.revokeObjectURL(href), 60_000)
}

export const medicalApi = {
  /* ── Doctor portal — the Internal Medical Flow ────────────────────── */
  doctor: {
    me:            ()        => api.get('/doctor/me').then(r => r.data?.data ?? r.data),
    updateProfile: (data)    => api.put('/doctor/me', data).then(r => r.data),
    summary:       ()        => api.get('/doctor/summary').then(r => r.data?.data ?? r.data),

    // Every call carries the side the doctor is working on: 'tpv' | 'purchase'.
    vendors: (module, params = {}) =>
      api.get(`/doctor/${module}/vendors`, { params }).then(r => r.data?.data ?? r.data),
    workers: (module, params = {}) =>
      api.get(`/doctor/${module}/workers`, { params }).then(r => r.data?.data ?? r.data),
    worker: (module, id) =>
      api.get(`/doctor/${module}/workers/${id}`).then(r => r.data?.data ?? r.data),

    // The examination form. Sent as multipart because it can carry a report
    // file alongside the signature and camera capture.
    examine: (module, workerId, payload) =>
      upload(`/doctor/${module}/workers/${workerId}/examination`, toFormData(payload)),

    examinations: (module, params = {}) =>
      api.get(`/doctor/${module}/examinations`, { params }).then(r => r.data?.data ?? r.data),
    examination: (module, id) =>
      api.get(`/doctor/${module}/examinations/${id}`).then(r => r.data?.data ?? r.data),
    certificate: (module, id) =>
      openPdf(`/doctor/${module}/examinations/${id}/certificate`),
  },

  /* ── Admin register + quality check ───────────────────────────────── */
  // `module` selects the register: 'tpv' | 'purchase'. The two APIs are route
  // for route identical, which is what lets one screen serve both.
  admin: {
    list:   (module, params = {}) => api.get(`/${module}/medical`, { params }).then(r => r.data),
    get:    (module, id)          => api.get(`/${module}/medical/${id}`).then(r => r.data?.data ?? r.data),
    decide: (module, id, data)    => api.post(`/${module}/medical/${id}/decide`, data).then(r => r.data),
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
    batches:    (module)          => api.get(`/${module}/medical/batches`).then(r => r.data?.data ?? r.data),

    // The Medical report — totals, vendor stats, successes/failures, health
    // ratings — and the vendor table as a spreadsheet.
    report: (module, params = {}) =>
      api.get(`/${module}/medical/report`, { params }).then(r => r.data?.data ?? r.data),
    reportExport: (module, params = {}, format = 'csv') =>
      download(`/${module}/medical/report/export`, `medical-report-${module}.${format}`, { ...params, format }),

    workerHistory: (module, workerId) =>
      api.get(
        module === 'tpv'
          ? `/tpv/workers/${workerId}/medical-history`
          : `/purchase/workforce/workers/${workerId}/medical-history`,
      ).then(r => r.data?.data ?? r.data),
  },

  /* ── Vendor portal — the External Medical Flow ────────────────────── */
  // `base` is '/portal' for TPV vendors and '/portal/purchase' for Purchase
  // vendors; the two portals expose the same shape under different prefixes.
  portal: {
    list:   (base) => api.get(`${base}/medical`).then(r => r.data),
    get:    (base, id) => api.get(`${base}/medical/${id}`).then(r => r.data?.data ?? r.data),

    store: (base, workerId, payload) =>
      upload(`${base}/workers/${workerId}/medical/external`, toFormData(payload)),

    resubmit: (base, id, payload) => upload(`${base}/medical/${id}/resubmit`, toFormData(payload)),
    comment:  (base, id, payload) => upload(`${base}/medical/${id}/comment`, toFormData(payload)),

    template:   (base, format = 'csv') =>
      download(`${base}/medical/template`, `medical-certificates-template.${format}`, { format }),
    bulkUpload: (base, payload) => upload(`${base}/medical/bulk`, toFormData(payload)),
    batches:    (base)          => api.get(`${base}/medical/batches`).then(r => r.data?.data ?? r.data),

    certificate: (base, id) => openPdf(`${base}/medical/${id}/certificate`),
    document:    (base, id) => openPdf(`${base}/medical/${id}/document`),
  },

  /* ── Doctor directory (admin) ─────────────────────────────────────── */
  doctors: {
    list:   (params = {}) => api.get('/medical/doctors', { params }).then(r => r.data?.data ?? r.data),
    create: (data)        => api.post('/medical/doctors', data).then(r => r.data),
    update: (id, data)    => api.put(`/medical/doctors/${id}`, data).then(r => r.data),
    // Deactivates; an issued certificate must keep naming its author.
    deactivate: (id)      => api.delete(`/medical/doctors/${id}`).then(r => r.data),
  },

  /* ── Public verification (no auth — what the QR opens) ─────────────── */
  verify: (certificateNo) =>
    api.get(`/public/medical/verify/${encodeURIComponent(certificateNo)}`).then(r => r.data?.data ?? r.data),
}

export default medicalApi
