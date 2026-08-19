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
    workStartLetter: (id)        => api.get(`/tpv/onboarding/${id}/work-start-letter`, { responseType: 'blob' }).then(r => r.data),
    acceptKickoff:   (id, comment) => api.post(`/tpv/onboarding/${id}/kickoff/accept`, comment ? { comment } : {}).then(r => r.data),
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
    // Issuing/returning PPE lives on `ppe` below (it moves Inventory stock).
    // This only records a deliberate skip of the step.
    skipPpe:         (id, data = {}) => api.post(`/tpv/workers/${id}/mark-ppe`, { ...data, ppe_status: 'skip' }).then(r => r.data),
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

  // ── HSSE governance dashboard (Doc 6) — one command view. ──
  governance: {
    dashboard: () => api.get('/tpv/governance/dashboard').then(r => r.data),
    report: (kind, anchor) => api.get('/tpv/governance/report', { params: { kind, anchor } }).then(r => r.data),
  },

  // ── Proactive safety engagement (Doc_4 Phase 5/6). ──
  safety: {
    observations:     (params = {}) => api.get('/tpv/observations', { params }).then(r => r.data?.data ?? r.data),
    createObservation:(data)        => api.post('/tpv/observations', data).then(r => r.data),
    closeObservation: (id, data = {}) => api.post(`/tpv/observations/${id}/close`, data).then(r => r.data),
    talks:            (params = {}) => api.get('/tpv/toolbox-talks', { params }).then(r => r.data?.data ?? r.data),
    createTalk:       (data)        => api.post('/tpv/toolbox-talks', data).then(r => r.data),
  },

  // ── Permit-to-Work + JSA (Doc_4 Phase 5). ──
  permits: {
    list:     (params = {}) => api.get('/tpv/permits', { params }).then(r => r.data?.data ?? r.data),
    get:      (id)          => api.get(`/tpv/permits/${id}`).then(r => r.data),
    create:   (data)        => api.post('/tpv/permits', data).then(r => r.data),
    addStep:  (id, data)    => api.post(`/tpv/permits/${id}/steps`, data).then(r => r.data),
    approve:  (id, remarks) => api.post(`/tpv/permits/${id}/approve`, { remarks }).then(r => r.data),
    reject:   (id, remarks) => api.post(`/tpv/permits/${id}/reject`, { remarks }).then(r => r.data),
    activate: (id)          => api.post(`/tpv/permits/${id}/activate`).then(r => r.data),
    close:    (id)          => api.post(`/tpv/permits/${id}/close`).then(r => r.data),
  },

  // ── HSSE incidents → RCA → CAPA (Doc_4 Phase 5). Serious/Fatal or stop-work
  // incidents auto-suspend the vendor; close requires RCA + all CAPAs verified. ──
  incidents: {
    list:       (params = {}) => api.get('/tpv/incidents', { params }).then(r => r.data?.data ?? r.data),
    get:        (id)          => api.get(`/tpv/incidents/${id}`).then(r => r.data),
    create:     (data)        => api.post('/tpv/incidents', data).then(r => r.data),
    recordRca:  (id, data)    => api.post(`/tpv/incidents/${id}/rca`, data).then(r => r.data),
    close:      (id)          => api.post(`/tpv/incidents/${id}/close`).then(r => r.data),
    addCapa:    (id, data)    => api.post(`/tpv/incidents/${id}/capas`, data).then(r => r.data),
    updateCapa: (id, capaId, data) => api.patch(`/tpv/incidents/${id}/capas/${capaId}`, data).then(r => r.data),
  },

  // ── Vendor/TPV employees (enhancement #2/#9/#10) — a vendor's assignable
  // people. list() feeds the assignee cascade; grantAccess() provisions a login
  // so a contact can be assigned work and see it. ──
  employees: {
    list:        (vendorId)            => api.get(`/tpv/vendors/${vendorId}/employees`).then(r => r.data?.data ?? r.data),
    create:      (vendorId, data)      => api.post(`/tpv/vendors/${vendorId}/employees`, data).then(r => r.data?.data ?? r.data),
    grantAccess: (vendorId, contactId) => api.post(`/tpv/vendors/${vendorId}/employees/${contactId}/grant-access`).then(r => r.data?.data ?? r.data),
  },

  // ── Vendor master (shared with Purchase) — the onboarding vendor picker ─
  // ── Third-party vendors (master + portal login), scoped to tpv engagement ──
  vendors: {
    list:      (params = {}) => api.get('/vendors', { params: { engagement: 'tpv', ...params } }).then(r => r.data),
    // Same endpoint, paginated envelope. Split from list() rather than folded
    // into it because callers of list() (task pickers, kickoff, compliance)
    // destructure a bare array — passing per_page is what opts into
    // { data, current_page, last_page, per_page, total }.
    listPaged: (params = {}) => api.get('/vendors', { params: { engagement: 'tpv', per_page: 25, ...params } }).then(r => r.data),
    get:       (id)          => api.get(`/vendors/${id}`).then(r => r.data),
    // Compliance suspension (admin). The nightly sweep does this automatically on
    // expired statutory docs; these are the manual controls.
    suspend:   (id, reason)  => api.post(`/vendors/${id}/suspend`, { reason }).then(r => r.data),
    reinstate: (id)          => api.post(`/vendors/${id}/reinstate`).then(r => r.data),
    tasks:     (id)          => api.get(`/tpv/vendors/${id}/tasks`).then(r => r.data),
    // Workspace Overview dashboard — live per-vendor counts + status.
    overview:  (id)          => api.get(`/tpv/vendors/${id}/overview`).then(r => r.data),
    // VRS scorecard (Doc 5) — { live, history }.
    scorecard: (id)          => api.get(`/tpv/vendors/${id}/scorecard`).then(r => r.data),
    // Customers directly linked to this vendor (clients.vendor_id).
    customers: {
      list:   (vid)       => api.get(`/tpv/vendors/${vid}/customers`).then(r => r.data),
      create: (vid, data) => api.post(`/tpv/vendors/${vid}/customers`, data).then(r => r.data),
    },
    // Follow-ups and notes on the shared polymorphic reminders/notes tables.
    // Under /tpv/* (role:admin,staff) rather than /sales/reminders, which has no
    // role gate — see VendorController.
    reminders: {
      list:     (vid)          => api.get(`/tpv/vendors/${vid}/reminders`).then(r => r.data),
      create:   (vid, data)    => api.post(`/tpv/vendors/${vid}/reminders`, data).then(r => r.data),
      complete: (vid, id, data = {}) => api.post(`/tpv/vendors/${vid}/reminders/${id}/complete`, data).then(r => r.data),
      remove:   (vid, id)      => api.delete(`/tpv/vendors/${vid}/reminders/${id}`).then(r => r.data),
    },
    // Commercial: the optional link to this company's Purchase record, plus the
    // two views Purchase exposes no vendor-level endpoint for. The other five
    // document types read straight off purchaseApi with a purchase_vendor_id.
    purchase: {
      matches:   (vid)      => api.get(`/tpv/vendors/${vid}/purchase-matches`).then(r => r.data),
      link:      (vid, purchase_vendor_id) => api.patch(`/tpv/vendors/${vid}/purchase-link`, { purchase_vendor_id }).then(r => r.data),
      // Register this company in Purchase and link it in one step, so commercial
      // work can start without retyping it into the Purchase module.
      createRecord: (vid) => api.post(`/tpv/vendors/${vid}/purchase-record`).then(r => r.data),
      payments:  (vid)      => api.get(`/tpv/vendors/${vid}/purchase-payments`).then(r => r.data),
      statement: (vid)      => api.get(`/tpv/vendors/${vid}/purchase-statement`).then(r => r.data),
    },
    // Folder-tree file area. Google Drive / OneDrive files arrive here too — the
    // pickers download the bytes and post them through upload() like any file.
    attachments: {
      browse: (vid, folderId = null) =>
        api.get(`/tpv/vendors/${vid}/attachments`, { params: folderId ? { folder_id: folderId } : {} }).then(r => r.data),
      upload: (vid, file, { folderId = null, source = 'upload', sourceRef = null } = {}) => {
        const fd = new FormData()
        fd.append('file', file)
        if (folderId) fd.append('folder_id', folderId)
        if (source) fd.append('source', source)
        if (sourceRef) fd.append('source_ref', sourceRef)
        return api.post(`/tpv/vendors/${vid}/attachments`, fd, { headers: { 'Content-Type': undefined } }).then(r => r.data)
      },
      rename:     (vid, id, name)     => api.put(`/tpv/vendors/${vid}/attachments/${id}`, { name }).then(r => r.data),
      move:       (vid, id, folder_id) => api.put(`/tpv/vendors/${vid}/attachments/${id}`, { folder_id }).then(r => r.data),
      remove:     (vid, id)           => api.delete(`/tpv/vendors/${vid}/attachments/${id}`).then(r => r.data),
      download:   (vid, id)           => api.get(`/tpv/vendors/${vid}/attachments/${id}/download`, { responseType: 'blob' }).then(r => r.data),
      createFolder: (vid, name, parent_id = null) =>
        api.post(`/tpv/vendors/${vid}/attachment-folders`, { name, parent_id }).then(r => r.data),
      renameFolder: (vid, id, name) => api.put(`/tpv/vendors/${vid}/attachment-folders/${id}`, { name }).then(r => r.data),
      removeFolder: (vid, id)       => api.delete(`/tpv/vendors/${vid}/attachment-folders/${id}`).then(r => r.data),
    },
    notes: {
      list:   (vid)           => api.get(`/tpv/vendors/${vid}/notes`).then(r => r.data),
      create: (vid, data)     => api.post(`/tpv/vendors/${vid}/notes`, data).then(r => r.data),
      update: (vid, id, data) => api.put(`/tpv/vendors/${vid}/notes/${id}`, data).then(r => r.data),
      remove: (vid, id)       => api.delete(`/tpv/vendors/${vid}/notes/${id}`).then(r => r.data),
    },
    stats:     ()            => api.get('/vendors/stats').then(r => r.data),
    resendActivation: (id)   => api.post(`/vendors/${id}/resend-activation`).then(r => r.data),
    create:    (data)        => api.post('/vendors', data).then(r => r.data),
    update:    (id, data)    => api.put(`/vendors/${id}`, data).then(r => r.data),
    approve:   (id, remarks = '') => api.post(`/vendors/${id}/approve`, { remarks }).then(r => r.data),
    setStatus: (id, status)  => api.patch(`/vendors/${id}/status`, { status }).then(r => r.data),
    sendEmail: (id, data)    => api.post(`/vendors/${id}/email`, data).then(r => r.data),
    // Mints a one-time set-password link and returns the pre-filled subject/body.
    // Sending still goes through sendEmail above.
    loginLink: (id)          => api.get(`/vendors/${id}/login-link`).then(r => r.data),
    delete:    (id)          => api.delete(`/vendors/${id}`).then(r => r.data),
  },
  // ── PPE — served from INVENTORY (single source of truth) ────────────
  ppe: {
    catalogue:   ()               => api.get('/tpv/ppe').then(r => r.data),
    summary:     ()               => api.get('/tpv/ppe/summary').then(r => r.data),
    forWorker:   (workerId)       => api.get(`/tpv/ppe/workers/${workerId}`).then(r => r.data),
    issue:       (workerId, data) => api.post(`/tpv/ppe/workers/${workerId}/issue`, data).then(r => r.data),
    returnIssue: (issueId, data)  => api.post(`/tpv/ppe/issues/${issueId}/return`, data).then(r => r.data),
    holders:     (productId)      => api.get(`/tpv/ppe/item/${productId}/holders`).then(r => r.data),
    // Private file: fetched as a blob so the bearer token is sent.
    imageBlob:   (productId)      => api.get(`/tpv/ppe/item/${productId}/image`, { responseType: 'blob' }).then(r => URL.createObjectURL(r.data)),

    // ── Requirement matrix: role → required PPE (admin-configurable) ──
    requirements:       ()          => api.get('/tpv/ppe/requirements').then(r => r.data),
    addRequirement:     (data)      => api.post('/tpv/ppe/requirements', data).then(r => r.data),
    updateRequirement:  (id, data)  => api.put(`/tpv/ppe/requirements/${id}`, data).then(r => r.data),
    deleteRequirement:  (id)        => api.delete(`/tpv/ppe/requirements/${id}`).then(r => r.data),
    compliance:         ()          => api.get('/tpv/ppe/compliance').then(r => r.data),
    workerCompliance:   (workerId)  => api.get(`/tpv/ppe/compliance/workers/${workerId}`).then(r => r.data),
  },

}

export default tpvApi
