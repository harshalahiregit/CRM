/**
 * TPV (Third-Party Vendor) API Service
 * Onboarding wizard + the statutory-document validation endpoints.
 * Mirrors the structure of services/purchaseApi.js.
 */

import api from '@/lib/api'

// Multipart helper — the shared axios instance defaults to application/json, so
// Content-Type must be cleared for the browser to set the multipart boundary.
const upload = (url, formData) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined } }).then(r => r.data)

export const tpvApi = {
  // ── Dashboard — one read-only roll-up across the whole module ───────
  dashboard: {
    get: () => api.get('/tpv/dashboard').then(r => r.data),
  },

  // ── Onboarding — the 6-step wizard ──────────────────────────────────
  onboarding: {
    list:     (params = {}) => api.get('/tpv/onboarding', { params }).then(r => r.data),
    stats:    ()            => api.get('/tpv/onboarding/stats').then(r => r.data),
    get:      (id)          => api.get(`/tpv/onboarding/${id}`).then(r => r.data),
    progress: (id)          => api.get(`/tpv/onboarding/${id}/progress`).then(r => r.data),
    create:   (data)        => api.post('/tpv/onboarding', data).then(r => r.data),
    delete:   (id)          => api.delete(`/tpv/onboarding/${id}`).then(r => r.data),
    // Wizard actions
    saveProfile: (id, profile) => api.post(`/tpv/onboarding/${id}/profile`, { profile }).then(r => r.data),
    setStep:     (id, step)    => api.patch(`/tpv/onboarding/${id}/step`, { step }).then(r => r.data),
    submit:      (id)          => api.post(`/tpv/onboarding/${id}/submit`).then(r => r.data),
    // Admin
    approve:         (id, remarks = '') => api.post(`/tpv/onboarding/${id}/approve`, { remarks }).then(r => r.data),
    requestResubmit: (id, remarks)      => api.post(`/tpv/onboarding/${id}/resubmit`, { remarks }).then(r => r.data),
  },

  // ── Statutory documents — the validation engine ─────────────────────
  documents: {
    // The required-vs-uploaded matrix for a vendor.
    checklist: (vendorId) => api.get(`/tpv/vendors/${vendorId}/documents`).then(r => r.data),
    upload:    (vendorId, type, file) => {
      const fd = new FormData()
      fd.append('type', type)
      fd.append('file', file)
      return upload(`/tpv/vendors/${vendorId}/documents`, fd)
    },
    // Resubmit replaces the file on an existing document; its type is fixed.
    resubmit: (documentId, file) => {
      const fd = new FormData()
      fd.append('file', file)
      return upload(`/tpv/documents/${documentId}/resubmit`, fd)
    },
    review:   (documentId, decision, remarks = '') =>
      api.post(`/tpv/documents/${documentId}/review`, { decision, remarks }).then(r => r.data),
    delete:   (documentId) => api.delete(`/tpv/documents/${documentId}`).then(r => r.data),
    downloadUrl: (documentId) => `/tpv/documents/${documentId}/download`,
    // Fetch as a blob so the private, token-authed file can be opened in a tab.
    open: async (documentId) => {
      const res = await api.get(`/tpv/documents/${documentId}/download`, { responseType: 'blob' })
      return URL.createObjectURL(res.data)
    },
  },

  // ── Workforce — the 5-step registration ────────────────────────────
  workers: {
    list:     (params = {}) => api.get('/tpv/workers', { params }).then(r => r.data),
    stats:    ()            => api.get('/tpv/workers/stats').then(r => r.data),
    get:      (id)          => api.get(`/tpv/workers/${id}`).then(r => r.data),
    progress: (id)          => api.get(`/tpv/workers/${id}/progress`).then(r => r.data),
    create:   (data)        => api.post('/tpv/workers', data).then(r => r.data),
    update:   (id, data)    => api.put(`/tpv/workers/${id}`, data).then(r => r.data),
    delete:   (id)          => api.delete(`/tpv/workers/${id}`).then(r => r.data),
    // Step records
    saveMedical:   (id, data) => api.post(`/tpv/workers/${id}/medical`, data).then(r => r.data),
    saveInduction: (id, data) => api.post(`/tpv/workers/${id}/induction`, data).then(r => r.data),
    issuePpe:      (id, data) => api.post(`/tpv/workers/${id}/ppe`, data).then(r => r.data),
    removePpe:     (id, ppeId) => api.delete(`/tpv/workers/${id}/ppe/${ppeId}`).then(r => r.data),
    // Admin — site access. activate() returns { worker, qr_token }; the token is
    // surfaced exactly once, at issue time.
    activate:  (id, validUntil = null) => api.post(`/tpv/workers/${id}/activate`, validUntil ? { valid_until: validUntil } : {}).then(r => r.data),
    // Reveal the badge QR for reprinting — admin-only, audited server-side.
    badge:     (id) => api.get(`/tpv/workers/${id}/badge`).then(r => r.data),
    suspend:   (id, remarks = '') => api.post(`/tpv/workers/${id}/suspend`, { remarks }).then(r => r.data),
    reinstate: (id)               => api.post(`/tpv/workers/${id}/reinstate`).then(r => r.data),
    terminate: (id, remarks)      => api.post(`/tpv/workers/${id}/terminate`, { remarks }).then(r => r.data),
  },

  // ── Gate (authed reads — scanning itself is the public gateScanApi) ──
  gate: {
    stats:            ()            => api.get('/tpv/gate/stats').then(r => r.data),
    log:              (params = {}) => api.get('/tpv/gate-log', { params }).then(r => r.data),
    roster:           (date = null) => api.get('/tpv/attendance', { params: date ? { date } : {} }).then(r => r.data),
    workerAttendance: (workerId, days = 30) => api.get(`/tpv/workers/${workerId}/attendance`, { params: { days } }).then(r => r.data),
  },

  // ── Safety strikes — reading is operational, issuing/voiding is admin ──
  strikes: {
    list:      (params = {}) => api.get('/tpv/strikes', { params }).then(r => r.data),
    stats:     ()            => api.get('/tpv/strikes/stats').then(r => r.data),
    forWorker: (workerId)    => api.get(`/tpv/workers/${workerId}/strikes`).then(r => r.data),
    // Issuing may auto-terminate — the response reports whether it did.
    issue:     (workerId, data) => api.post(`/tpv/workers/${workerId}/strikes`, data).then(r => r.data),
    void:      (strikeId, reason) => api.post(`/tpv/strikes/${strikeId}/void`, { reason }).then(r => r.data),
  },

  // ── Vendor master (shared with Purchase) — the onboarding vendor picker ─
  // ── Third-party vendors (master + portal login), scoped to tpv engagement ──
  vendors: {
    list:      (params = {}) => api.get('/vendors', { params: { engagement: 'tpv', ...params } }).then(r => r.data),
    get:       (id)          => api.get(`/vendors/${id}`).then(r => r.data),
    create:    (data)        => api.post('/vendors', data).then(r => r.data),
    update:    (id, data)    => api.put(`/vendors/${id}`, data).then(r => r.data),
    setStatus: (id, status)  => api.patch(`/vendors/${id}/status`, { status }).then(r => r.data),
    delete:    (id)          => api.delete(`/vendors/${id}`).then(r => r.data),
  },
}

export default tpvApi
