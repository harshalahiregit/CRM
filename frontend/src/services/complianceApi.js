/**
 * Compliance engine API — templates, checklists, and the signature chain.
 *
 * Generic by design (mirrors App\Services\Compliance on the backend): checklists
 * attach to any allowlisted subject, so this is not a TPV service even though
 * TPV is currently its only consumer with a nav entry.
 */

import api from '@/lib/api'

// Multipart helper — the shared axios instance defaults to application/json, so
// Content-Type must be cleared for the browser to set the multipart boundary.
const upload = (url, formData) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined } }).then(r => r.data)

export const complianceApi = {
  // ── Templates — reading is operational; authoring is admin-only ─────
  templates: {
    list:     (params = {}) => api.get('/compliance/templates', { params }).then(r => r.data),
    meta:     ()            => api.get('/compliance/templates/meta').then(r => r.data),
    get:      (id)          => api.get(`/compliance/templates/${id}`).then(r => r.data),
    create:   (data)        => api.post('/compliance/templates', data).then(r => r.data),
    update:   (id, data)    => api.put(`/compliance/templates/${id}`, data).then(r => r.data),
    activate: (id)          => api.post(`/compliance/templates/${id}/activate`).then(r => r.data),
    archive:  (id)          => api.post(`/compliance/templates/${id}/archive`).then(r => r.data),
    clone:    (id)          => api.post(`/compliance/templates/${id}/clone`).then(r => r.data),
    delete:   (id)          => api.delete(`/compliance/templates/${id}`).then(r => r.data),
  },

  // ── Checklists ─────────────────────────────────────────────────────
  checklists: {
    list:  (params = {}) => api.get('/compliance/checklists', { params }).then(r => r.data),
    stats: ()            => api.get('/compliance/checklists/stats').then(r => r.data),
    get:   (id)          => api.get(`/compliance/checklists/${id}`).then(r => r.data),
    // The response carries fill_token — disclosed once, here and on reopen.
    issue: (data)        => api.post('/compliance/checklists', data).then(r => r.data),
    saveResponses: (id, responses) => api.patch(`/compliance/checklists/${id}/responses`, { responses }).then(r => r.data),

    // Sign-off. Multipart because a tier may attach a signature image.
    managerSign: (id, data) => upload(`/compliance/checklists/${id}/manager-sign`, toForm(data)),
    headSign:    (id, data) => upload(`/compliance/checklists/${id}/head-sign`, toForm(data)),
    reopen:      (id)       => api.post(`/compliance/checklists/${id}/reopen`).then(r => r.data),
  },

  // ── Subject pickers ────────────────────────────────────────────────
  // Thin wrappers over the shared endpoints, mirroring how purchaseApi and
  // tpvApi each wrap /vendors themselves. A module importing another module's
  // service would break §3; calling a shared HTTP endpoint does not.
  subjects: {
    vendor: (params = {}) => api.get('/vendors', { params }).then(r => r.data),
    worker: (params = {}) => api.get('/tpv/workers', { params }).then(r => r.data),
  },
}

/** Booleans must go over multipart as 1/0 — PHP reads "false" as truthy. */
function toForm({ action, remarks, signature, override_segregation }) {
  const fd = new FormData()
  fd.append('action', action)
  if (remarks) fd.append('remarks', remarks)
  if (signature) fd.append('signature', signature)
  if (override_segregation) fd.append('override_segregation', '1')

  return fd
}

export default complianceApi
