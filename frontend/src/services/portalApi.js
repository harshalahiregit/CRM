/**
 * Vendor Self-Service Portal API.
 *
 * Uses the shared axios client on purpose: a vendor has a real login, so the
 * bearer token + 401→/auth/login behaviour is exactly right here (unlike the
 * public gate-scan / checklist-fill clients, which are bare because their users
 * have no login). Every endpoint resolves the vendor from the token server-side
 * — there is no vendor id to pass.
 *
 * TPV self-service section: mirrors the tpvApi shape so existing page components
 * (TpvOnboardingWizard, TpvWorkers, etc.) can swap between tpvApi and portalApi
 * based on user.role without any changes to the component logic.
 */
import api from '@/lib/api'

const upload = (url, formData) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined } }).then(r => r.data)

export const portalApi = {
  me:         () => api.get('/portal/me').then(r => r.data),

  // ── My Work — projects/tasks/tickets assigned to this vendor / TPV ──────
  // Role-gated (not vendor.portal), so it works even before a vendor-master
  // profile exists. Returns the unwrapped payload.
  myWork: {
    summary:  () => api.get('/portal/my-work/summary').then(r => r.data?.data ?? r.data),
    projects: () => api.get('/portal/my-work/projects').then(r => r.data?.data ?? r.data),
    tasks:    () => api.get('/portal/my-work/tasks').then(r => r.data?.data ?? r.data),
    taskStatuses:    () => api.get('/portal/my-work/task-statuses').then(r => r.data?.data ?? r.data),
    updateTaskStatus:(id, status) => api.patch(`/portal/my-work/tasks/${id}/status`, { status }).then(r => r.data?.data ?? r.data),
    tickets:  () => api.get('/portal/my-work/tickets').then(r => r.data?.data ?? r.data),
    raiseTicket:  (body) => api.post('/portal/my-work/tickets', body).then(r => r.data),
    ticket:       (id) => api.get(`/portal/my-work/tickets/${id}`).then(r => r.data),
    replyTicket:  (id, message) => api.post(`/portal/my-work/tickets/${id}/reply`, { message }).then(r => r.data),
    expenses:     () => api.get('/portal/my-work/expenses').then(r => r.data?.data ?? r.data),
    logExpense:   (body) => api.post('/portal/my-work/expenses', body).then(r => r.data),
    kb:       () => api.get('/portal/my-work/kb').then(r => r.data?.data ?? r.data),
    kbArticle: (slug) => api.get(`/portal/my-work/kb/${slug}`).then(r => r.data?.data ?? r.data),
  },

  // ── Onboarding — mirrors tpvApi.onboarding shape ───────────────────────
  // list() wraps the single-record response in an array so TpvOnboardings
  // renders without modification. stats() is derived client-side from the record.
  onboarding: {
    list: async () => {
      const r = await api.get('/portal/onboarding')
      const ob = r.data?.onboarding
      return { data: ob ? [ob] : [] }
    },
    stats: async () => {
      const r = await api.get('/portal/onboarding')
      const ob = r.data?.onboarding
      if (!ob) return { total: 0, in_progress: 0, awaiting: 0, approved: 0, rejected: 0 }
      return {
        total:       1,
        in_progress: ob.status === 'In_Progress' ? 1 : 0,
        awaiting:    ob.status === 'Submitted' || ob.status === 'Under_Review' ? 1 : 0,
        approved:    ob.status === 'Approved' ? 1 : 0,
        rejected:    ob.status === 'Rejected' ? 1 : 0,
      }
    },
    get:      (id) => api.get(`/portal/onboarding/${id}`).then(r => r.data),
    progress: (id) => api.get(`/portal/onboarding/${id}/progress`).then(r => r.data),
    // Wizard write actions
    saveProfile:     (id, profile) => api.post(`/portal/onboarding/${id}/profile`, { profile }).then(r => r.data),
    setStep:         (id, step)    => api.patch(`/portal/onboarding/${id}/step`, { step }).then(r => r.data),
    submit:          (id, data={}) => api.post(`/portal/onboarding/${id}/submit`, data).then(r => r.data),
    // Step 1 — Kickoff PDF
    kickoffPdf:      (id)          => api.get(`/portal/onboarding/${id}/kickoff`, { responseType: 'blob' }).then(r => r.data),
    workStartLetter: (id)          => api.get(`/portal/onboarding/${id}/work-start-letter`, { responseType: 'blob' }).then(r => r.data),
    acceptKickoff:   (id, comment) => api.post(`/portal/onboarding/${id}/kickoff/accept`, comment ? { comment } : {}).then(r => r.data),
    logKickoffEvent: (id, event)   => api.post(`/portal/onboarding/${id}/kickoff/log`, { event }).then(r => r.data),
    // Admin-only — vendors cannot create, approve or delete onboardings
    create:          ()   => Promise.reject(new Error('Not available in vendor portal')),
    delete:          ()   => Promise.reject(new Error('Not available in vendor portal')),
    approve:         ()   => Promise.reject(new Error('Admin only')),
    requestResubmit: ()   => Promise.reject(new Error('Admin only')),
  },

  // ── Documents — mirrors tpvApi.documents shape ──────────────────────────
  documents: {
    checklist: () => api.get('/portal/documents').then(r => r.data),
    upload:    (_vendorId, type, file) => {
      const fd = new FormData()
      fd.append('type', type)
      fd.append('file', file)
      return upload('/portal/documents', fd)
    },
    resubmit: (documentId, file) => {
      const fd = new FormData()
      fd.append('file', file)
      return upload(`/portal/documents/${documentId}/resubmit`, fd)
    },
    open: async (documentId) => {
      const res = await api.get(`/portal/documents/${documentId}/download`, { responseType: 'blob' })
      return URL.createObjectURL(res.data)
    },
    // Admin-only
    review: () => Promise.reject(new Error('Admin only')),
    delete: () => Promise.reject(new Error('Admin only')),
    versions: () => Promise.resolve([]),
  },

  // ── Contacts — mirrors tpvApi.contacts shape ────────────────────────────
  // vendorId param is accepted but ignored — the backend resolves own vendor.
  contacts: {
    list:      (_vendorId, params={}) => api.get('/portal/contacts', { params }).then(r => r.data),
    create:    (_vendorId, data)      => api.post('/portal/contacts', data).then(r => r.data),
    update:    (_vendorId, id, data)  => api.put(`/portal/contacts/${id}`, data).then(r => r.data),
    setStatus: (_vendorId, id, status)=> api.patch(`/portal/contacts/${id}/status`, { status }).then(r => r.data),
  },

  // ── Workers — mirrors tpvApi.workers shape ──────────────────────────────
  // vendor_id in params is silently overridden server-side.
  workers: {
    list:          (params={}) => api.get('/portal/workers', { params }).then(r => r.data),
    stats:         ()          => api.get('/portal/workers/stats').then(r => r.data),
    get:           (id)        => api.get(`/portal/workers/${id}`).then(r => r.data),
    progress:      (id)        => api.get(`/portal/workers/${id}/progress`).then(r => r.data),
    create:        (data)      => api.post('/portal/workers', data).then(r => r.data),
    update:        (id, data)  => api.put(`/portal/workers/${id}`, data).then(r => r.data),
    saveMedical:   (id, data)  => api.post(`/portal/workers/${id}/medical`, data).then(r => r.data),
    saveInduction: (id, data)  => api.post(`/portal/workers/${id}/induction`, data).then(r => r.data),
    // Portal-owned, ownership-checked. These two used to hit the admin /tpv/*
    // routes, which forced third_party_vendor into the admin role gate.
    markPunch:       (id, punch_count, punch_reason) => api.post(`/portal/workers/${id}/mark-punch`, { punch_count, punch_reason }).then(r => r.data),
    markCardStatus:  (id, card_status) => api.post(`/portal/workers/${id}/mark-card-status`, { card_status }).then(r => r.data),
    uploadWorkers: (file, vendor_id = null) => {
      const fd = new FormData()
      fd.append('worker_file', file)
      if (vendor_id) fd.append('vendor_id', vendor_id)
      return upload('/tpv/workers/upload', fd)
    },
    // Read-only: the vendor VIEWS the admin-issued badge and, until it is issued,
    // sees exactly what is still blocking it. Issuing itself stays admin-only.
    badge:     (id) => api.get(`/portal/workers/${id}/badge`).then(r => r.data),
    // Admin-only
    activate:  () => Promise.reject(new Error('Requires admin approval')),
    suspend:   () => Promise.reject(new Error('Admin only')),
    reinstate: () => Promise.reject(new Error('Admin only')),
    terminate: () => Promise.reject(new Error('Admin only')),
    delete:    () => Promise.reject(new Error('Admin only')),
  },

  // ── Gate — mirrors tpvApi.gate shape ────────────────────────────────────
  gate: {
    stats:            ()             => api.get('/portal/gate/stats').then(r => r.data),
    log:              (params={})    => api.get('/portal/gate-log', { params }).then(r => r.data),
    roster:           (date=null)    => api.get('/portal/attendance', { params: date ? { date } : {} }).then(r => r.data),
    workerAttendance: (wid, days=30) => api.get(`/portal/workers/${wid}/attendance`, { params: { days } }).then(r => r.data),
  },

  // ── Strikes — mirrors tpvApi.strikes shape ──────────────────────────────
  strikes: {
    list:      (params={}) => api.get('/portal/strikes', { params }).then(r => r.data),
    stats:     ()          => Promise.resolve({ total: 0 }),
    forWorker: (wid)       => api.get(`/portal/workers/${wid}/strikes`).then(r => r.data),
    // Admin-only
    issue: () => Promise.reject(new Error('Admin only')),
    void:  () => Promise.reject(new Error('Admin only')),
  },

  // ── Vendor — mirrors tpvApi.vendors.get() for vendor detail fetches ─────
  // In portal context "get vendor" always means own vendor — no id needed.
  vendors: {
    get:       ()   => api.get('/portal/me').then(r => r.data?.vendor ?? null),
    list:      ()   => Promise.resolve([]),
    setStatus: ()   => Promise.reject(new Error('Admin only')),
  },

  // ── Purchase-side portal (unchanged) ────────────────────────────────────
  orders:  () => api.get('/portal/orders').then(r => r.data),
  order:   (id) => api.get(`/portal/orders/${id}`).then(r => r.data),
  invoices: () => api.get('/portal/invoices').then(r => r.data),
  invoice: (id) => api.get(`/portal/invoices/${id}`).then(r => r.data),

  // Legacy flat API (kept for PortalDashboard, PortalDocuments)
  uploadDocument: (type, file) => {
    const fd = new FormData()
    fd.append('type', type)
    fd.append('file', file)
    return upload('/portal/documents', fd)
  },
  resubmitDocument: (docId, file) => {
    const fd = new FormData()
    fd.append('file', file)
    return upload(`/portal/documents/${docId}/resubmit`, fd)
  },
  downloadDocument: (docId) => api.get(`/portal/documents/${docId}/download`, { responseType: 'blob' }).then(r => r.data),
  // ── PPE — served from INVENTORY (single source of truth) ────────────
  ppe: {
    catalogue:   ()               => api.get('/portal/ppe').then(r => r.data),
    summary:     ()               => api.get('/portal/ppe/summary').then(r => r.data),
    forWorker:   (workerId)       => api.get(`/portal/ppe/workers/${workerId}`).then(r => r.data),
    issue:       (workerId, data) => api.post(`/portal/ppe/workers/${workerId}/issue`, data).then(r => r.data),
    returnIssue: (issueId, data)  => api.post(`/portal/ppe/issues/${issueId}/return`, data).then(r => r.data),
    holders:     (productId)      => api.get(`/portal/ppe/item/${productId}/holders`).then(r => r.data),
    // Read-only: a vendor sees what its own workers still need, but cannot edit rules.
    workerCompliance: (workerId)  => api.get(`/portal/ppe/compliance/workers/${workerId}`).then(r => r.data),
    // Private file: fetched as a blob so the bearer token is sent.
    imageBlob:   (productId)      => api.get(`/portal/ppe/item/${productId}/image`, { responseType: 'blob' }).then(r => URL.createObjectURL(r.data)),
  },

  // §32 "View compliance" — the vendor's own compliance register (read-only).
  compliance: {
    get: () => api.get('/portal/compliance').then(r => r.data),
  },

  // General › Customer — the customers linked to this vendor (read-only).
  customers: () => api.get('/portal/customers').then(r => r.data?.data ?? r.data),

  // Compliance & HSSE — the vendor requests permits + reports incidents.
  hsse: {
    permits:        () => api.get('/portal/permits').then(r => r.data),
    requestPermit:  (body) => api.post('/portal/permits', body).then(r => r.data),
    incidents:      () => api.get('/portal/incidents').then(r => r.data),
    reportIncident: (body) => api.post('/portal/incidents', body).then(r => r.data),
  },

  // Pre Alert / Packages / Shipping — the vendor's dispatch notices.
  logistics: {
    shipments:      () => api.get('/portal/shipments').then(r => r.data),
    createShipment: (body) => api.post('/portal/shipments', body).then(r => r.data),
    updateStatus:   (id, status) => api.patch(`/portal/shipments/${id}/status`, { status }).then(r => r.data),
    packages:       () => api.get('/portal/shipment-packages').then(r => r.data),
  },

  // Performance — the vendor's own risk score, rating, penalties, awards, referrals.
  performance: {
    risk:           () => api.get('/portal/risk').then(r => r.data),
    feedback:       () => api.get('/portal/feedback').then(r => r.data),
    violations:     () => api.get('/portal/violations').then(r => r.data),
    awards:         () => api.get('/portal/awards').then(r => r.data),
    referrals:      () => api.get('/portal/referrals').then(r => r.data),
    submitReferral: (body) => api.post('/portal/referrals', body).then(r => r.data),
  },

  // §32 Governance-response half.
  governance: {
    ncrs:            ()            => api.get('/portal/ncrs').then(r => r.data),
    respondNcr:      (id, payload) => api.post(`/portal/ncrs/${id}/respond`, payload).then(r => r.data),
    capas:           ()            => api.get('/portal/capas').then(r => r.data),
    submitCapa:      (id, payload) => api.post(`/portal/capas/${id}/evidence`, payload).then(r => r.data),
    requestApproval: (payload)     => api.post('/portal/approvals/request', payload).then(r => r.data),
    requestExtension:(payload)     => api.post('/portal/extensions/request', payload).then(r => r.data),
    meetings:        ()            => api.get('/portal/meetings').then(r => r.data),
    meetingMom:      (id)          => api.get(`/portal/meetings/${id}/mom`).then(r => r.data),
    actions:         ()            => api.get('/portal/actions').then(r => r.data),
    respondAction:   (id, payload) => api.post(`/portal/actions/${id}/respond`, payload).then(r => r.data),
    ppeMatrix:       ()            => api.get('/portal/ppe-matrix').then(r => r.data),
    uploadCertificate: (workerId, fd) => upload(`/portal/workers/${workerId}/certificates`, fd),
  },

}

export default portalApi
