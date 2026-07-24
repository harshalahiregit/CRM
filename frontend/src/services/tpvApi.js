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

  // ── Advanced approval workflow (Phase 3) ────────────────────────────
  approvals: {
    list:     ()         => api.get('/tpv/approvals').then(r => r.data),
    history:  (id)       => api.get(`/tpv/onboarding/${id}/approvals`).then(r => r.data),
    decide:   (id, data) => api.post(`/tpv/onboarding/${id}/approvals/decide`, data).then(r => r.data),
    escalate: ()         => api.post('/tpv/approvals/escalate').then(r => r.data),
    delegations: {
      list:   ()     => api.get('/tpv/approval-delegations').then(r => r.data),
      create: (data) => api.post('/tpv/approval-delegations', data).then(r => r.data),
    },
  },

  // ── Temporary TPV access lifecycle (Phase 2) ────────────────────────
  access: {
    // Vendor's own server-authoritative countdown.
    countdown:       ()             => api.get('/tpv/access/countdown').then(r => r.data),
    // Admin / staff.
    list:            ()             => api.get('/tpv/vendors/temporary').then(r => r.data),
    createTemporary: (data)         => api.post('/tpv/vendors/temporary', data).then(r => r.data),
    extend:          (vendorId, d)  => api.post(`/tpv/vendors/${vendorId}/access/extend`, d).then(r => r.data),
    expire:          (vendorId)     => api.post(`/tpv/vendors/${vendorId}/access/expire`).then(r => r.data),
    convert:         (vendorId)     => api.post(`/tpv/vendors/${vendorId}/access/convert`).then(r => r.data),
    status:          (vendorId)     => api.get(`/tpv/vendors/${vendorId}/access/status`).then(r => r.data),
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
    submit:      (id, data = {}) => api.post(`/tpv/onboarding/${id}/submit`, data).then(r => r.data),
    // Step 1 — Kickoff PDF (blob), acknowledgement, and view/download/print logging.
    kickoffPdf:      (id)        => api.get(`/tpv/onboarding/${id}/kickoff`, { responseType: 'blob' }).then(r => r.data),
    acceptKickoff:   (id)        => api.post(`/tpv/onboarding/${id}/kickoff/accept`).then(r => r.data),
    logKickoffEvent: (id, event) => api.post(`/tpv/onboarding/${id}/kickoff/log`, { event }).then(r => r.data),
    // Admin
    approve:         (id, remarks = '') => api.post(`/tpv/onboarding/${id}/approve`, { remarks }).then(r => r.data),
    reject:          (id, remarks = '') => api.post(`/tpv/onboarding/${id}/reject`, { remarks }).then(r => r.data),
    hold:            (id, remarks = '') => api.post(`/tpv/onboarding/${id}/hold`, { reason: remarks, remarks }).then(r => r.data),
    release:         (id)               => api.post(`/tpv/onboarding/${id}/release`).then(r => r.data),
    requestResubmit: (id, remarks = '') => api.post(`/tpv/onboarding/${id}/resubmit`, { remarks }).then(r => r.data),
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

    // ── Version history (Phase 3) ─────────────────────────────────────
    versions:        (documentId)            => api.get(`/tpv/documents/${documentId}/versions`).then(r => r.data),
    downloadVersion: (documentId, versionId) => api.get(`/tpv/documents/${documentId}/versions/${versionId}/download`, { responseType: 'blob' }).then(r => r.data),
    restoreVersion:  (documentId, versionId) => api.post(`/tpv/documents/${documentId}/versions/${versionId}/restore`).then(r => r.data),
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
    // Step records & 1:1 Reference Actions
    toggleStatus:    (id, is_active) => api.patch(`/tpv/workers/${id}/toggle-status`, { is_active }).then(r => r.data),
    uploadWorkers:   (file, vendor_id) => {
      const fd = new FormData()
      fd.append('worker_file', file)
      fd.append('vendor_id', vendor_id)
      return upload('/tpv/workers/upload', fd)
    },
    saveMedical:     (id, data) => api.post(`/tpv/workers/${id}/medical`, data).then(r => r.data),
    markMedical:     (id, data) => api.post(`/tpv/workers/${id}/mark-medical`, data).then(r => r.data),
    saveInduction:   (id, data) => api.post(`/tpv/workers/${id}/induction`, data).then(r => r.data),
    markInduction:   (id, data) => api.post(`/tpv/workers/${id}/mark-induction`, data).then(r => r.data),
    getPpeInventory: ()         => api.get('/tpv/workers/ppe-inventory').then(r => r.data),
    updatePpeStock:  (data)       => api.post('/tpv/workers/ppe-stock', data).then(r => r.data),
    issuePpe:        (id, data) => api.post(`/tpv/workers/${id}/ppe`, data).then(r => r.data),
    markPpe:         (id, data) => api.post(`/tpv/workers/${id}/mark-ppe`, data).then(r => r.data),
    removePpe:       (id, ppeId) => api.delete(`/tpv/workers/${id}/ppe/${ppeId}`).then(r => r.data),
    markCardStatus:  (id, card_status) => api.post(`/tpv/workers/${id}/mark-card-status`, { card_status }).then(r => r.data),
    markPunch:       (id, punch_count, punch_reason) => api.post(`/tpv/workers/${id}/mark-punch`, { punch_count, punch_reason }).then(r => r.data),
    decide:          (id, status, remarks = '') => api.post(`/tpv/workers/${id}/decide`, { status, remarks }).then(r => r.data),
    scanAccess:      (token) => api.get(`/scan-access/${token}`).then(r => r.data),
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

  // ── Vendor contacts — master contact list per vendor (no hard delete) ──
  contacts: {
    list:      (vendorId, params = {}) => api.get(`/tpv/vendors/${vendorId}/contacts`, { params }).then(r => r.data),
    get:       (vendorId, id)          => api.get(`/tpv/vendors/${vendorId}/contacts/${id}`).then(r => r.data),
    create:    (vendorId, data)        => api.post(`/tpv/vendors/${vendorId}/contacts`, data).then(r => r.data),
    update:    (vendorId, id, data)    => api.put(`/tpv/vendors/${vendorId}/contacts/${id}`, data).then(r => r.data),
    setStatus: (vendorId, id, status)  => api.patch(`/tpv/vendors/${vendorId}/contacts/${id}/status`, { status }).then(r => r.data),
  },

  // ── Vendor master (shared with Purchase) — the onboarding vendor picker ─
  // ── Third-party vendors (master + portal login), scoped to tpv engagement ──
  vendors: {
    list:      (params = {}) => api.get('/vendors', { params: { engagement: 'tpv', ...params } }).then(r => r.data),
    get:       (id)          => api.get(`/vendors/${id}`).then(r => r.data),
    create:    (data)        => api.post('/vendors', data).then(r => r.data),
    update:    (id, data)    => api.put(`/vendors/${id}`, data).then(r => r.data),
    approve:   (id, remarks = '') => api.post(`/vendors/${id}/approve`, { remarks }).then(r => r.data),
    setStatus: (id, status)  => api.patch(`/vendors/${id}/status`, { status }).then(r => r.data),
    sendEmail: (id, data)    => api.post(`/vendors/${id}/email`, data).then(r => r.data),
    delete:    (id)          => api.delete(`/vendors/${id}`).then(r => r.data),
  },
}

export default tpvApi
