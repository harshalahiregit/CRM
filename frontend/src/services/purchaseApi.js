/**
 * Purchase Module API Service
 * All HTTP calls to /api/purchase/* and the shared /api/vendors/* master.
 * Mirrors the structure of services/hrApi.js.
 */

import api from '@/lib/api'

// Multipart helper — clear Content-Type so the browser sets the boundary.
const upload = (url, formData) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined } }).then(r => r.data)

export const purchaseApi = {
  // ── Unified procure-to-pay dashboard ────────────────────────────────
  dashboard: {
    get: () => api.get('/purchase/dashboard').then(r => r.data),
  },

  // ── Quotations / RFQ (Draft → Sent → Under Review → Awarded) ──────────
  rfqs: {
    list:    (params = {}) => api.get('/purchase/rfqs', { params }).then(r => r.data),
    stats:   ()            => api.get('/purchase/rfqs/stats').then(r => r.data),
    get:     (id)          => api.get(`/purchase/rfqs/${id}`).then(r => r.data),
    comparison: (id)       => api.get(`/purchase/rfqs/${id}/comparison`).then(r => r.data),
    create:  (data)        => api.post('/purchase/rfqs', data).then(r => r.data),
    update:  (id, data)    => api.put(`/purchase/rfqs/${id}`, data).then(r => r.data),
    send:    (id)          => api.post(`/purchase/rfqs/${id}/send`).then(r => r.data),
    cancel:  (id, remarks = '') => api.post(`/purchase/rfqs/${id}/cancel`, { remarks }).then(r => r.data),
    delete:  (id)          => api.delete(`/purchase/rfqs/${id}`).then(r => r.data),
    // Record a vendor's quotation against this RFQ.
    recordQuote: (rfqId, data) => api.post(`/purchase/rfqs/${rfqId}/quotations`, data).then(r => r.data),
  },
  quotations: {
    get:       (id)       => api.get(`/purchase/quotations/${id}`).then(r => r.data),
    update:    (id, data) => api.put(`/purchase/quotations/${id}`, data).then(r => r.data),
    shortlist: (id, on = true) => api.post(`/purchase/quotations/${id}/shortlist`, { on }).then(r => r.data),
    reject:    (id, remarks = '') => api.post(`/purchase/quotations/${id}/reject`, { remarks }).then(r => r.data),
    award:     (id)       => api.post(`/purchase/quotations/${id}/award`).then(r => r.data),   // admin-only
  },

  // ── Catalog (item master: Draft → Active → Discontinued) ────────────
  catalog: {
    list:    (params = {}) => api.get('/purchase/catalog', { params }).then(r => r.data),
    stats:   ()            => api.get('/purchase/catalog/stats').then(r => r.data),
    search:  (q = '')      => api.get('/purchase/catalog/search', { params: { q } }).then(r => r.data),   // Active only
    get:     (id)          => api.get(`/purchase/catalog/${id}`).then(r => r.data),
    create:  (data)        => api.post('/purchase/catalog', data).then(r => r.data),
    update:  (id, data)    => api.put(`/purchase/catalog/${id}`, data).then(r => r.data),
    setStatus: (id, status) => api.post(`/purchase/catalog/${id}/status`, { status }).then(r => r.data),
    delete:  (id)          => api.delete(`/purchase/catalog/${id}`).then(r => r.data),
  },

  // ── Contracts (MSA / rate contracts: Draft → Under Review → Active) ──
  contracts: {
    list:    (params = {}) => api.get('/purchase/contracts', { params }).then(r => r.data),
    stats:   ()            => api.get('/purchase/contracts/stats').then(r => r.data),
    get:     (id)          => api.get(`/purchase/contracts/${id}`).then(r => r.data),
    referenceable: (vendorId) => api.get('/purchase/contracts/referenceable', { params: { vendor_id: vendorId } }).then(r => r.data),
    create:  (data)        => api.post('/purchase/contracts', data).then(r => r.data),
    update:  (id, data)    => api.put(`/purchase/contracts/${id}`, data).then(r => r.data),
    delete:  (id)          => api.delete(`/purchase/contracts/${id}`).then(r => r.data),
    submit:  (id)          => api.post(`/purchase/contracts/${id}/submit`).then(r => r.data),
    returnToDraft: (id)    => api.post(`/purchase/contracts/${id}/return`).then(r => r.data),
    activate: (id)         => api.post(`/purchase/contracts/${id}/activate`).then(r => r.data),   // admin-only
    terminate: (id, reason = '') => api.post(`/purchase/contracts/${id}/terminate`, { reason }).then(r => r.data), // admin-only
    uploadDocument: (id, file) => {
      const fd = new FormData(); fd.append('document', file)
      return api.post(`/purchase/contracts/${id}/document`, fd, { headers: { 'Content-Type': undefined } }).then(r => r.data)
    },
  },

  // ── Purchase Requests (Draft → Submitted → Approved → Converted) ──────
  requests: {
    list:    (params = {}) => api.get('/purchase/requests', { params }).then(r => r.data),
    stats:   ()            => api.get('/purchase/requests/stats').then(r => r.data),
    get:     (id)          => api.get(`/purchase/requests/${id}`).then(r => r.data),
    create:  (data)        => api.post('/purchase/requests', data).then(r => r.data),
    update:  (id, data)    => api.put(`/purchase/requests/${id}`, data).then(r => r.data),
    delete:  (id)          => api.delete(`/purchase/requests/${id}`).then(r => r.data),
    // Workflow actions
    submit:  (id)               => api.post(`/purchase/requests/${id}/submit`).then(r => r.data),
    approve: (id, remarks = '') => api.post(`/purchase/requests/${id}/approve`, { remarks }).then(r => r.data),
    reject:  (id, remarks)      => api.post(`/purchase/requests/${id}/reject`, { remarks }).then(r => r.data),
  },

  // ── Purchase Orders (Draft → Issued → Received → Closed) ─────────────
  orders: {
    list:        (params = {}) => api.get('/purchase/orders', { params }).then(r => r.data),
    stats:       ()            => api.get('/purchase/orders/stats').then(r => r.data),
    get:         (id)          => api.get(`/purchase/orders/${id}`).then(r => r.data),
    create:      (data)        => api.post('/purchase/orders', data).then(r => r.data),
    fromRequest: (prId)        => api.post(`/purchase/orders/from-request/${prId}`).then(r => r.data),
    update:      (id, data)    => api.put(`/purchase/orders/${id}`, data).then(r => r.data),
    delete:      (id)          => api.delete(`/purchase/orders/${id}`).then(r => r.data),
    // Lifecycle
    issue:  (id)               => api.post(`/purchase/orders/${id}/issue`).then(r => r.data),
    close:  (id, remarks = '') => api.post(`/purchase/orders/${id}/close`, { remarks }).then(r => r.data),
    cancel: (id, remarks = '') => api.post(`/purchase/orders/${id}/cancel`, { remarks }).then(r => r.data),
  },

  // ── Goods Receipts (GRN) — nested under a PO for create/list ─────────
  receipts: {
    listForOrder: (orderId)       => api.get(`/purchase/orders/${orderId}/receipts`).then(r => r.data),
    create:       (orderId, data) => api.post(`/purchase/orders/${orderId}/receipts`, data).then(r => r.data),
    get:          (id)            => api.get(`/purchase/receipts/${id}`).then(r => r.data),
    confirm:      (id)            => api.post(`/purchase/receipts/${id}/confirm`).then(r => r.data),
    cancel:       (id, remarks = '') => api.post(`/purchase/receipts/${id}/cancel`, { remarks }).then(r => r.data),
    delete:       (id)            => api.delete(`/purchase/receipts/${id}`).then(r => r.data),
  },

  // ── Purchase Invoices (Draft → Awaiting → Partially Paid → Paid) ─────
  invoices: {
    list:      (params = {}) => api.get('/purchase/invoices', { params }).then(r => r.data),
    stats:     ()            => api.get('/purchase/invoices/stats').then(r => r.data),
    get:       (id)          => api.get(`/purchase/invoices/${id}`).then(r => r.data),
    // 3-way match preview: billed vs PO-ordered vs GRN-accepted, per line.
    match:     (id)          => api.get(`/purchase/invoices/${id}/match`).then(r => r.data),
    create:    (data)        => api.post('/purchase/invoices', data).then(r => r.data),
    fromOrder: (poId, data = {}) => api.post(`/purchase/invoices/from-order/${poId}`, data).then(r => r.data),
    update:    (id, data)    => api.put(`/purchase/invoices/${id}`, data).then(r => r.data),
    delete:    (id)          => api.delete(`/purchase/invoices/${id}`).then(r => r.data),
    approve:   (id)          => api.post(`/purchase/invoices/${id}/approve`).then(r => r.data),
    cancel:    (id, remarks = '') => api.post(`/purchase/invoices/${id}/cancel`, { remarks }).then(r => r.data),
    // Payments
    addPayment:    (id, data)        => api.post(`/purchase/invoices/${id}/payments`, data).then(r => r.data),
    deletePayment: (id, paymentId)   => api.delete(`/purchase/invoices/${id}/payments/${paymentId}`).then(r => r.data),
  },

  // ── Debit Notes / Order Returns (Draft → Open → Settled) ────────────
  debitNotes: {
    list:    (params = {}) => api.get('/purchase/debit-notes', { params }).then(r => r.data),
    stats:   ()            => api.get('/purchase/debit-notes/stats').then(r => r.data),
    get:     (id)          => api.get(`/purchase/debit-notes/${id}`).then(r => r.data),
    create:  (data)        => api.post('/purchase/debit-notes', data).then(r => r.data),
    update:  (id, data)    => api.put(`/purchase/debit-notes/${id}`, data).then(r => r.data),
    delete:  (id)          => api.delete(`/purchase/debit-notes/${id}`).then(r => r.data),
    issue:   (id)          => api.post(`/purchase/debit-notes/${id}/issue`).then(r => r.data),
    cancel:  (id, remarks = '') => api.post(`/purchase/debit-notes/${id}/cancel`, { remarks }).then(r => r.data),
    // Vendor refunds against the debit note
    addRefund:    (id, data)      => api.post(`/purchase/debit-notes/${id}/refunds`, data).then(r => r.data),
    deleteRefund: (id, refundId)  => api.delete(`/purchase/debit-notes/${id}/refunds/${refundId}`).then(r => r.data),
    // Credit netting — apply the open balance against a same-vendor payable invoice
    applicableInvoices: (id)       => api.get(`/purchase/debit-notes/${id}/applicable-invoices`).then(r => r.data),
    applyCredit:        (id, data) => api.post(`/purchase/debit-notes/${id}/applications`, data).then(r => r.data),
    reverseCredit:      (id, appId) => api.delete(`/purchase/debit-notes/${id}/applications/${appId}`).then(r => r.data),
  },

  // ── Vendor master (shared with TPV) — Purchase engagement ───────────
  // Uses the shared /vendors endpoints, always scoped to engagement 'purchase'.
  // Mirrors tpvApi.vendors so the shared TPV vendor components work unchanged.
  vendors: {
    list:      (params = {}) => api.get('/vendors', { params: { engagement: 'purchase', ...params } }).then(r => r.data),
    stats:     ()            => api.get('/vendors/stats', { params: { engagement: 'purchase' } }).then(r => r.data),
    get:       (id)          => api.get(`/vendors/${id}`).then(r => r.data),
    create:    (data)        => api.post('/vendors', { ...data, engagements: ['purchase'] }).then(r => r.data),
    update:    (id, data)    => api.put(`/vendors/${id}`, data).then(r => r.data),
    setStatus: (id, status)  => api.patch(`/vendors/${id}/status`, { status }).then(r => r.data),
    sendEmail: (id, data)    => api.post(`/vendors/${id}/email`, data).then(r => r.data),
    delete:    (id)          => api.delete(`/vendors/${id}`).then(r => r.data),
  },

  // ── Purchase onboarding — the 6-step wizard (/purchase/onboarding) ───
  // Mirrors tpvApi.onboarding (incl. decisions + kickoff) so the shared wizard works.
  onboarding: {
    list:     (params = {}) => api.get('/purchase/onboarding', { params }).then(r => r.data),
    stats:    ()            => api.get('/purchase/onboarding/stats').then(r => r.data),
    get:      (id)          => api.get(`/purchase/onboarding/${id}`).then(r => r.data),
    progress: (id)          => api.get(`/purchase/onboarding/${id}/progress`).then(r => r.data),
    create:   (data)        => api.post('/purchase/onboarding', data).then(r => r.data),
    delete:   (id)          => api.delete(`/purchase/onboarding/${id}`).then(r => r.data),
    saveProfile: (id, profile) => api.post(`/purchase/onboarding/${id}/profile`, { profile }).then(r => r.data),
    setStep:     (id, step)    => api.patch(`/purchase/onboarding/${id}/step`, { step }).then(r => r.data),
    submit:      (id, data = {}) => api.post(`/purchase/onboarding/${id}/submit`, data).then(r => r.data),
    // Step 1 — kickoff PDF / acknowledgement (reuses the shared kickoff engine).
    kickoffPdf:      (id)        => api.get(`/purchase/onboarding/${id}/kickoff`, { responseType: 'blob' }).then(r => r.data),
    acceptKickoff:   (id)        => api.post(`/purchase/onboarding/${id}/kickoff/accept`).then(r => r.data),
    logKickoffEvent: (id, event) => api.post(`/purchase/onboarding/${id}/kickoff/log`, { event }).then(r => r.data),
    // Admin decisions (mirror tpvApi.onboarding).
    approve:         (id, remarks = '') => api.post(`/purchase/onboarding/${id}/approve`, { remarks }).then(r => r.data),
    reject:          (id, remarks = '') => api.post(`/purchase/onboarding/${id}/reject`, { remarks }).then(r => r.data),
    hold:            (id, remarks = '') => api.post(`/purchase/onboarding/${id}/hold`, { reason: remarks, remarks }).then(r => r.data),
    release:         (id)               => api.post(`/purchase/onboarding/${id}/release`).then(r => r.data),
    requestResubmit: (id, remarks = '') => api.post(`/purchase/onboarding/${id}/resubmit`, { remarks }).then(r => r.data),
  },

  // ── Vendor contacts — mirrors tpvApi.contacts (/purchase/vendors/{v}/contacts) ─
  contacts: {
    list:      (vendorId, params = {}) => api.get(`/purchase/vendors/${vendorId}/contacts`, { params }).then(r => r.data),
    get:       (vendorId, id)          => api.get(`/purchase/vendors/${vendorId}/contacts/${id}`).then(r => r.data),
    create:    (vendorId, data)        => api.post(`/purchase/vendors/${vendorId}/contacts`, data).then(r => r.data),
    update:    (vendorId, id, data)    => api.put(`/purchase/vendors/${vendorId}/contacts/${id}`, data).then(r => r.data),
    setStatus: (vendorId, id, status)  => api.patch(`/purchase/vendors/${vendorId}/contacts/${id}/status`, { status }).then(r => r.data),
  },

  // ── Approval actions (admin) — the onboarding decision endpoints ────
  approvals: {
    approve:         (id, remarks = '') => api.post(`/purchase/onboarding/${id}/approve`, { remarks }).then(r => r.data),
    reject:          (id, remarks = '') => api.post(`/purchase/onboarding/${id}/reject`, { remarks }).then(r => r.data),
    hold:            (id, reason = '')  => api.post(`/purchase/onboarding/${id}/hold`, { reason }).then(r => r.data),
    release:         (id)               => api.post(`/purchase/onboarding/${id}/release`).then(r => r.data),
    requestResubmit: (id, remarks = '') => api.post(`/purchase/onboarding/${id}/resubmit`, { remarks }).then(r => r.data),
  },

  // ── Statutory documents (reuse the shared engine, /purchase surface) ─
  documents: {
    checklist: (vendorId) => api.get(`/purchase/vendors/${vendorId}/documents`).then(r => r.data),
    upload:    (vendorId, type, file) => {
      const fd = new FormData()
      fd.append('type', type)
      fd.append('file', file)
      return upload(`/purchase/vendors/${vendorId}/documents`, fd)
    },
    resubmit: (documentId, file) => {
      const fd = new FormData()
      fd.append('file', file)
      return upload(`/purchase/documents/${documentId}/resubmit`, fd)
    },
    review:   (documentId, decision, remarks = '') =>
      api.post(`/purchase/documents/${documentId}/review`, { decision, remarks }).then(r => r.data),
    delete:   (documentId) => api.delete(`/purchase/documents/${documentId}`).then(r => r.data),
    versions:        (documentId)            => api.get(`/purchase/documents/${documentId}/versions`).then(r => r.data),
    downloadVersion: (documentId, versionId) => api.get(`/purchase/documents/${documentId}/versions/${versionId}/download`, { responseType: 'blob' }).then(r => r.data),
    restoreVersion:  (documentId, versionId) => api.post(`/purchase/documents/${documentId}/versions/${versionId}/restore`).then(r => r.data),
    // Fetch as a blob so the private, token-authed file opens in a new tab.
    open: async (documentId) => {
      const res = await api.get(`/purchase/documents/${documentId}/download`, { responseType: 'blob' })
      return URL.createObjectURL(res.data)
    },
  },

  // A vendor-bound document api matching the shape PurchaseVendorDocuments wants,
  // so the same component works for admin (bound to a vendorId) and the portal.
  documentsFor: (vendorId) => ({
    checklist: ()          => purchaseApi.documents.checklist(vendorId),
    upload:    (type, file) => purchaseApi.documents.upload(vendorId, type, file),
    resubmit:  (docId, file) => purchaseApi.documents.resubmit(docId, file),
    review:    (docId, decision, remarks) => purchaseApi.documents.review(docId, decision, remarks),
    open:      (docId)      => purchaseApi.documents.open(docId),
  }),
}

export default purchaseApi
