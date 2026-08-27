/**
 * Purchase Vendor Portal API — hits /portal/purchase/*. Every endpoint resolves
 * the vendor from the Purchase-vendor token server-side; there is no vendor id to
 * pass. Uses the dedicated Purchase-vendor axios instance (its own token),
 * completely independent of the shared user/vendor auth.
 */
import api from '@/lib/purchaseVendorApi'

const upload = (url, formData) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined } }).then(r => r.data)

export const purchasePortalApi = {
  // Persist the welcome-banner dismissal server-side (never localStorage).
  dismissWelcomeBanner: () => api.post('/portal/purchase/welcome/dismiss').then(r => r.data),

  me: () => api.get('/portal/purchase/me').then(r => r.data),

  // Self-service profile + commercial fields (business fields only; never
  // code/category/status/auth). Maps to PUT /portal/purchase/profile.
  updateProfile: (payload) => api.put('/portal/purchase/profile', payload).then(r => r.data),

  // ── Onboarding (the vendor's own record) ────────────────────────────────
  // list() wraps the single-record response in an array for list-style
  // rendering; stats() is derived client-side from the record.
  onboarding: {
    list: async () => {
      const r = await api.get('/portal/purchase/onboarding')
      const ob = r.data?.onboarding
      return { data: ob ? [ob] : [] }
    },
    stats: async () => {
      const r = await api.get('/portal/purchase/onboarding')
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
    // Direct bootstrap of the caller's onboarding record, if needed elsewhere.
    self:     ()             => api.get('/portal/purchase/onboarding').then(r => r.data),
    get:      (id)           => api.get(`/portal/purchase/onboarding/${id}`).then(r => r.data),
    progress: (id)           => api.get(`/portal/purchase/onboarding/${id}/progress`).then(r => r.data),
    saveProfile: (id, profile) => api.post(`/portal/purchase/onboarding/${id}/profile`, { profile }).then(r => r.data),
    setStep:     (id, step)    => api.patch(`/portal/purchase/onboarding/${id}/step`, { step }).then(r => r.data),
    submit:      (id, data = {}) => api.post(`/portal/purchase/onboarding/${id}/submit`, data).then(r => r.data),
    // Step 1 — kickoff PDF / acknowledgement (by onboarding, own-vendor scoped).
    kickoffPdf:      (id)        => api.get(`/portal/purchase/onboarding/${id}/kickoff`, { responseType: 'blob' }).then(r => r.data),
    acceptKickoff:   (id)        => api.post(`/portal/purchase/onboarding/${id}/kickoff/accept`).then(r => r.data),
    logKickoffEvent: (id, event) => api.post(`/portal/purchase/onboarding/${id}/kickoff/log`, { event }).then(r => r.data),
    // Admin-only — a portal vendor cannot create, approve, hold or delete.
    create:          ()   => Promise.reject(new Error('Not available in vendor portal')),
    delete:          ()   => Promise.reject(new Error('Not available in vendor portal')),
    approve:         ()   => Promise.reject(new Error('Admin only')),
    reject:          ()   => Promise.reject(new Error('Admin only')),
    hold:            ()   => Promise.reject(new Error('Admin only')),
    release:         ()   => Promise.reject(new Error('Admin only')),
    requestResubmit: ()   => Promise.reject(new Error('Admin only')),
  },

  // ── Documents — mirrors portalApi.documents shape (upload takes 3 args) ──
  documents: {
    checklist: ()          => api.get('/portal/purchase/documents').then(r => r.data),
    upload:    (_vendorId, type, file) => {
      const fd = new FormData()
      fd.append('type', type)
      fd.append('file', file)
      return upload('/portal/purchase/documents', fd)
    },
    resubmit: (documentId, file) => {
      const fd = new FormData()
      fd.append('file', file)
      return upload(`/portal/purchase/documents/${documentId}/resubmit`, fd)
    },
    open: async (documentId) => {
      const res = await api.get(`/portal/purchase/documents/${documentId}/download`, { responseType: 'blob' })
      return URL.createObjectURL(res.data)
    },
    // Admin-only — vendors cannot review or delete their own documents.
    review:   () => Promise.reject(new Error('Admin only')),
    delete:   () => Promise.reject(new Error('Admin only')),
    versions: () => Promise.resolve([]),
  },

  // ── Contacts — mirrors portalApi.contacts shape (vendorId ignored) ──────
  contacts: {
    list:      (_vendorId, params = {}) => api.get('/portal/purchase/contacts', { params }).then(r => r.data),
    create:    (_vendorId, data)        => api.post('/portal/purchase/contacts', data).then(r => r.data),
    update:    (_vendorId, id, data)    => api.put(`/portal/purchase/contacts/${id}`, data).then(r => r.data),
    setStatus: (_vendorId, id, status)  => api.patch(`/portal/purchase/contacts/${id}/status`, { status }).then(r => r.data),
  },

  // ── Vendor — own vendor only (mirrors portalApi.vendors.get) ────────────
  vendors: {
    get:       () => api.get('/portal/purchase/me').then(r => r.data?.vendor ?? null),
    list:      () => Promise.resolve([]),
    setStatus: () => Promise.reject(new Error('Admin only')),
  },

  // ── Workforce — the vendor's own workers, resolved from the token ───────
  // No vendor_id is ever sent: the server reads it from the PurchaseVendor token
  // and 404s any worker that is not the caller's.
  workers: {
    list:      (params = {}) => api.get('/portal/purchase/workers', { params }).then(r => r.data),
    summary:   ()            => api.get('/portal/purchase/workers/summary').then(r => r.data),
    get:       (id)          => api.get(`/portal/purchase/workers/${id}`).then(r => r.data),
    create:    (data)        => api.post('/portal/purchase/workers', data).then(r => r.data),
    update:    (id, data)    => api.put(`/portal/purchase/workers/${id}`, data).then(r => r.data),
    remove:    (id)          => api.delete(`/portal/purchase/workers/${id}`).then(r => r.data),
    readiness: (id)          => api.get(`/portal/purchase/workers/${id}/readiness`).then(r => r.data),
    medical:   (id, data)    => api.post(`/portal/purchase/workers/${id}/medical`, data).then(r => r.data),
    training:  (id, data)    => api.post(`/portal/purchase/workers/${id}/training`, data).then(r => r.data),
    induction: (id, data)    => api.post(`/portal/purchase/workers/${id}/induction`, data).then(r => r.data),
    document:  (id, fd)      => api.post(`/portal/purchase/workers/${id}/documents`, fd).then(r => r.data),
    // Step 5 is READ ONLY here — activation is an admin decision.
    badge:     (id)          => api.get(`/portal/purchase/workers/${id}/badge`).then(r => r.data),
    activate:  ()            => Promise.reject(new Error('Admin only')),
  },

  // ── PPE — served from central INVENTORY (single source of truth) ────────
  // Issue/return move inventory_stock through StockService; the vendor never
  // supplies a warehouse, so it cannot move stock between sites.
  ppe: {
    catalogue: ()          => api.get('/portal/purchase/ppe').then(r => r.data),
    summary:   ()          => api.get('/portal/purchase/ppe/summary').then(r => r.data),
    // Private file: fetched as a blob so the bearer token is sent.
    imageBlob: (productId) => api.get(`/portal/purchase/ppe/item/${productId}/image`, { responseType: 'blob' }).then(r => URL.createObjectURL(r.data)),
    forWorker:  (workerId)        => api.get(`/portal/purchase/workers/${workerId}/ppe`).then(r => r.data),
    compliance: (workerId)        => api.get(`/portal/purchase/workers/${workerId}/ppe/compliance`).then(r => r.data),
    issue:      (workerId, data)  => api.post(`/portal/purchase/workers/${workerId}/ppe/issue`, data).then(r => r.data),
    return:     (issueId, data)   => api.post(`/portal/purchase/ppe/issues/${issueId}/return`, data).then(r => r.data),
  },

  // Standalone kickoff summary (the portal Kickoff tab, resolved from the token).
  kickoff: {
    get:    () => api.get('/portal/purchase/kickoff').then(r => r.data),
    accept: () => api.post('/portal/purchase/kickoff/accept').then(r => r.data),
  },

  // ── Commercial — own vendor only, read-only lists + detail (with items) ──
  // The vendor sees the documents raised against them: orders, quotations,
  // contracts, invoices, debit notes, payments, and a running statement.
  commercial: {
    orders:      ()   => api.get('/portal/purchase/orders').then(r => r.data),
    order:       (id) => api.get(`/portal/purchase/orders/${id}`).then(r => r.data),
    quotations:  ()   => api.get('/portal/purchase/quotations').then(r => r.data),
    quotation:   (id) => api.get(`/portal/purchase/quotations/${id}`).then(r => r.data),
    contracts:   ()   => api.get('/portal/purchase/contracts').then(r => r.data),
    contract:    (id) => api.get(`/portal/purchase/contracts/${id}`).then(r => r.data),
    invoices:    ()   => api.get('/portal/purchase/invoices').then(r => r.data),
    invoice:     (id) => api.get(`/portal/purchase/invoices/${id}`).then(r => r.data),
    debitNotes:  ()   => api.get('/portal/purchase/debit-notes').then(r => r.data),
    debitNote:   (id) => api.get(`/portal/purchase/debit-notes/${id}`).then(r => r.data),
    payments:    ()   => api.get('/portal/purchase/payments').then(r => r.data),
    statement:   ()   => api.get('/portal/purchase/statement').then(r => r.data),
  },

  // §32 "View compliance" — the vendor's own compliance register (read-only).
  compliance: {
    get: () => api.get('/portal/purchase/compliance').then(r => r.data),
  },

  // §32 Governance-response half (no PPE matrix — Purchase has none).
  governance: {
    ncrs:            ()            => api.get('/portal/purchase/ncrs').then(r => r.data),
    respondNcr:      (id, payload) => api.post(`/portal/purchase/ncrs/${id}/respond`, payload).then(r => r.data),
    capas:           ()            => api.get('/portal/purchase/capas').then(r => r.data),
    submitCapa:      (id, payload) => api.post(`/portal/purchase/capas/${id}/evidence`, payload).then(r => r.data),
    requestApproval: (payload)     => api.post('/portal/purchase/approvals/request', payload).then(r => r.data),
    requestExtension:(payload)     => api.post('/portal/purchase/extensions/request', payload).then(r => r.data),
    meetings:        ()            => api.get('/portal/purchase/meetings').then(r => r.data),
    meetingMom:      (id)          => api.get(`/portal/purchase/meetings/${id}/mom`).then(r => r.data),
    actions:         ()            => api.get('/portal/purchase/actions').then(r => r.data),
    respondAction:   (id, payload) => api.post(`/portal/purchase/actions/${id}/respond`, payload).then(r => r.data),
    uploadCertificate: (workerId, fd) => upload(`/portal/purchase/workers/${workerId}/certificates`, fd),
  },
}

export default purchasePortalApi
