/**
 * Purchase Module API Service
 * All HTTP calls to /api/purchase/* (incl. the Purchase-owned /api/purchase/vendors).
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
    list:      (params = {}) => api.get('/purchase/quotations', { params }).then(r => r.data),
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
    // Wires the existing GET /purchase/contracts/{id}/download route — the
    // signed contract file. Read-only; no new endpoint was added for it.
    download: async (id) => {
      const res = await api.get(`/purchase/contracts/${id}/download`, { responseType: 'blob' })
      return URL.createObjectURL(res.data)
    },
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
    // Register: every receipt for the tenant, looked up by GRN / delivery note
    // / vendor. listForOrder is the other direction — one PO's receipts.
    list:         (params = {})   => api.get('/purchase/receipts', { params }).then(r => r.data),
    stats:        ()              => api.get('/purchase/receipts/stats').then(r => r.data),
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

  // ── Purchase Vendor master — the Purchase-owned entity (/purchase/vendors) ─
  // Fully independent of the shared /vendors table and of TPV.
  vendors: {
    list:      (params = {}) => api.get('/purchase/vendors', { params }).then(r => r.data),
    stats:     ()            => api.get('/purchase/vendors/stats').then(r => r.data),
    get:       (id)          => api.get(`/purchase/vendors/${id}`).then(r => r.data),
    tasks:     (id)          => api.get(`/purchase/vendors/${id}/tasks`).then(r => r.data),
    create:    (data)        => api.post('/purchase/vendors', data).then(r => r.data),
    update:    (id, data)    => api.put(`/purchase/vendors/${id}`, data).then(r => r.data),
    setStatus: (id, status)  => api.patch(`/purchase/vendors/${id}/status`, { status }).then(r => r.data),
    approve:   (id)          => api.post(`/purchase/vendors/${id}/approve`).then(r => r.data),   // admin-only
    resendActivation: (id)   => api.post(`/purchase/vendors/${id}/resend-activation`).then(r => r.data), // admin-only
    delete:    (id)          => api.delete(`/purchase/vendors/${id}`).then(r => r.data),

    // ── Vendor detail workspace tabs ─────────────────────────────────────
    // Live per-vendor Overview counts (mirror of tpvApi.vendors.overview).
    overview:  (id) => api.get(`/purchase/vendors/${id}/overview`).then(r => r.data),

    // Customers directly linked to this vendor via clients.purchase_vendor_id
    // (mirror of tpvApi.vendors.customers). Add creates a real Customer record.
    customers: {
      list:   (vid)       => api.get(`/purchase/vendors/${vid}/customers`).then(r => r.data),
      create: (vid, data) => api.post(`/purchase/vendors/${vid}/customers`, data).then(r => r.data),
    },

    // Commercial: native. Every purchase document already keys to
    // purchase_vendor_id, so payments and the statement need no link step.
    payments:  (id) => api.get(`/purchase/vendors/${id}/payments`).then(r => r.data),
    statement: (id) => api.get(`/purchase/vendors/${id}/statement`).then(r => r.data),

    // Prequalification (scored questionnaire) + Due-Diligence checklist — the
    // Purchase-side mirror of tpvApi.vendors.prequalification / due-diligence.
    // GET is admin+staff; the write is admin-only server-side.
    prequalification:       (id)       => api.get(`/purchase/vendors/${id}/prequalification`).then(r => r.data),
    assessPrequalification: (id, data) => api.put(`/purchase/vendors/${id}/prequalification`, data).then(r => r.data),
    dueDiligence:           (id)       => api.get(`/purchase/vendors/${id}/due-diligence`).then(r => r.data),
    saveDueDiligence:       (id, data) => api.put(`/purchase/vendors/${id}/due-diligence`, data).then(r => r.data),

    // Notes / reminders / attachments ride the SHARED polymorphic engines,
    // addressed by model class — one table each, not a Purchase copy.
    notes: {
      list:   (vid)           => api.get(`/purchase/vendors/${vid}/notes`).then(r => r.data),
      create: (vid, data)     => api.post(`/purchase/vendors/${vid}/notes`, data).then(r => r.data),
      update: (vid, id, data) => api.put(`/purchase/vendors/${vid}/notes/${id}`, data).then(r => r.data),
      remove: (vid, id)       => api.delete(`/purchase/vendors/${vid}/notes/${id}`).then(r => r.data),
    },
    reminders: {
      list:     (vid)                => api.get(`/purchase/vendors/${vid}/reminders`).then(r => r.data),
      create:   (vid, data)          => api.post(`/purchase/vendors/${vid}/reminders`, data).then(r => r.data),
      complete: (vid, id, data = {}) => api.post(`/purchase/vendors/${vid}/reminders/${id}/complete`, data).then(r => r.data),
      remove:   (vid, id)            => api.delete(`/purchase/vendors/${vid}/reminders/${id}`).then(r => r.data),
    },
    appointments: {
      list:     (vid)       => api.get(`/purchase/vendors/${vid}/appointments`).then(r => r.data),
      create:   (vid, data) => api.post(`/purchase/vendors/${vid}/appointments`, data).then(r => r.data),
      complete: (vid, id, data) => api.patch(`/purchase/vendors/${vid}/appointments/${id}/complete`, data).then(r => r.data),
      remove:   (vid, id)   => api.delete(`/purchase/vendors/${vid}/appointments/${id}`).then(r => r.data),
    },
    attachments: {
      browse: (vid, folderId = null) =>
        api.get(`/purchase/vendors/${vid}/attachments`, { params: folderId ? { folder_id: folderId } : {} }).then(r => r.data),
      upload: (vid, file, { folderId = null, source = 'upload', sourceRef = null } = {}) => {
        const fd = new FormData()
        fd.append('file', file)
        if (folderId) fd.append('folder_id', folderId)
        if (source) fd.append('source', source)
        if (sourceRef) fd.append('source_ref', sourceRef)
        return api.post(`/purchase/vendors/${vid}/attachments`, fd, { headers: { 'Content-Type': undefined } }).then(r => r.data)
      },
      rename:       (vid, id, name)      => api.put(`/purchase/vendors/${vid}/attachments/${id}`, { name }).then(r => r.data),
      move:         (vid, id, folder_id) => api.put(`/purchase/vendors/${vid}/attachments/${id}`, { folder_id }).then(r => r.data),
      remove:       (vid, id)            => api.delete(`/purchase/vendors/${vid}/attachments/${id}`).then(r => r.data),
      download:     (vid, id)            => api.get(`/purchase/vendors/${vid}/attachments/${id}/download`, { responseType: 'blob' }).then(r => r.data),
      createFolder: (vid, name, parent_id = null) => api.post(`/purchase/vendors/${vid}/attachment-folders`, { name, parent_id }).then(r => r.data),
      renameFolder: (vid, id, name)      => api.put(`/purchase/vendors/${vid}/attachment-folders/${id}`, { name }).then(r => r.data),
      removeFolder: (vid, id)            => api.delete(`/purchase/vendors/${vid}/attachment-folders/${id}`).then(r => r.data),
    },
  },

  // ── Settings — module config (key/value) + the vendor-category master ───
  settings: {
    get:    ()      => api.get('/purchase/settings').then(r => r.data),
    update: (data)  => api.put('/purchase/settings', data).then(r => r.data),   // admin-only
  },
  vendorCategories: {
    list:   ()          => api.get('/purchase/vendor-categories').then(r => r.data),
    create: (data)      => api.post('/purchase/vendor-categories', data).then(r => r.data),
    update: (id, data)  => api.put(`/purchase/vendor-categories/${id}`, data).then(r => r.data),
    delete: (id)        => api.delete(`/purchase/vendor-categories/${id}`).then(r => r.data),
  },

  // ── Reports — read-only aggregations, all accept { period } ─────────────
  reports: {
    // Filter vocabulary (items / currencies / years), all from real rows.
    filters:      ()            => api.get('/purchase/reports/filters').then(r => r.data),
    itemCost:     (params = {}) => api.get('/purchase/reports/item-cost', { params }).then(r => r.data),
    poVoucher:    (params = {}) => api.get('/purchase/reports/po-voucher', { params }).then(r => r.data),
    orders:       (params = {}) => api.get('/purchase/reports/orders', { params }).then(r => r.data),
    invoices:     (params = {}) => api.get('/purchase/reports/invoices', { params }).then(r => r.data),
    statsByCount: (params = {}) => api.get('/purchase/reports/stats-by-count', { params }).then(r => r.data),
    statsByCost:  (params = {}) => api.get('/purchase/reports/stats-by-cost', { params }).then(r => r.data),
  },

  // ── Order Returns — goods returned to a vendor (Draft → Issued → Completed) ─
  // A separate document from debit notes: own OR-#### series + line discounts.
  orderReturns: {
    list:     (params = {}) => api.get('/purchase/order-returns', { params }).then(r => r.data),
    stats:    ()            => api.get('/purchase/order-returns/stats').then(r => r.data),
    get:      (id)          => api.get(`/purchase/order-returns/${id}`).then(r => r.data),
    create:   (data)        => api.post('/purchase/order-returns', data).then(r => r.data),
    update:   (id, data)    => api.put(`/purchase/order-returns/${id}`, data).then(r => r.data),
    delete:   (id)          => api.delete(`/purchase/order-returns/${id}`).then(r => r.data),
    issue:    (id)          => api.post(`/purchase/order-returns/${id}/issue`).then(r => r.data),
    complete: (id)          => api.post(`/purchase/order-returns/${id}/complete`).then(r => r.data),
    cancel:   (id, remarks = '') => api.post(`/purchase/order-returns/${id}/cancel`, { remarks }).then(r => r.data),
  },

  // ── Vendor Items — Purchase Vendor ↔ Inventory Item mapping ───────────
  // Purchase owns the mapping only; item groups/items come from Inventory APIs.
  vendorItems: {
    list:   (params = {}) => api.get('/purchase/vendor-items', { params }).then(r => r.data),
    stats:  ()            => api.get('/purchase/vendor-items/stats').then(r => r.data),
    get:    (id)          => api.get(`/purchase/vendor-items/${id}`).then(r => r.data),
    create: (data)        => api.post('/purchase/vendor-items', data).then(r => r.data),
    update: (id, data)    => api.put(`/purchase/vendor-items/${id}`, data).then(r => r.data),
    delete: (id)          => api.delete(`/purchase/vendor-items/${id}`).then(r => r.data),
  },

  // ── Vendor approval chain (/purchase/onboarding/{id}/approvals) ─────────
  // Named approvalChain, not approvals: a SECOND `approvals:` key further down
  // this same object literal silently overwrote this one, so `chain` and the
  // stage-scoped approve/reject below did not exist at runtime at all.
  approvalChain: {
    chain:   (onboardingId)                 => api.get(`/purchase/onboarding/${onboardingId}/approvals`).then(r => r.data),
    approve: (onboardingId, stage, remarks = '') => api.post(`/purchase/onboarding/${onboardingId}/approvals/${stage}/approve`, { remarks }).then(r => r.data),
    reject:  (onboardingId, stage, remarks)      => api.post(`/purchase/onboarding/${onboardingId}/approvals/${stage}/reject`, { remarks }).then(r => r.data),
  },

  // ── Central approval register (§12, /purchase/approval-requests) ────────
  // The generic register of ~18 governance approval types. Distinct from the
  // onboarding stage chain. Deciding is admin-only server-side.
  approvalRequests: {
    list:   (params = {}) => api.get('/purchase/approval-requests', { params }).then(r => r.data),
    create: (data)        => api.post('/purchase/approval-requests', data).then(r => r.data),
    decide: (id, data)    => api.post(`/purchase/approval-requests/${id}/decide`, data).then(r => r.data),
  },

  // ── Kickoff meetings (Purchase-owned engine, /purchase/kickoff) ─────────
  // Independent of the shared/TPV kickoff engine — hits only /api/purchase/kickoff.
  kickoff: {
    list:   (params = {}) => api.get('/purchase/kickoff', { params }).then(r => r.data),
    stats:  ()            => api.get('/purchase/kickoff/stats').then(r => r.data),
    dashboard: ()         => api.get('/purchase/kickoff/dashboard').then(r => r.data),
    previousSummary: (id) => api.get(`/purchase/kickoff/${id}/previous-summary`).then(r => r.data),
    carryForward: (id)    => api.post(`/purchase/kickoff/${id}/carry-forward`).then(r => r.data),
    // Cross-meeting registers — decisions, issues and the open-action backlog
    // read ACROSS every meeting rather than inside one. Method names and
    // parameters mirror kickoffApi.registers exactly, because the same
    // MeetingRegisters page renders both modules.
    registers: {
      options:   ()            => api.get('/purchase/kickoff/registers/options').then(r => r.data),
      decisions: (params = {}) => api.get('/purchase/kickoff/registers/decisions', { params }).then(r => r.data),
      issues:    (params = {}) => api.get('/purchase/kickoff/registers/issues', { params }).then(r => r.data),
      actions:   (params = {}) => api.get('/purchase/kickoff/registers/actions', { params }).then(r => r.data),
    },
    // Participant pickers + the live vendor snapshot a meeting is planned
    // against. `vendors` lists PURCHASE vendors — the shared engine's picker
    // reads the separate `vendors` table, whose ids are unrelated.
    staff:        ()         => api.get('/purchase/kickoff/staff').then(r => r.data),
    vendors:      ()         => api.get('/purchase/kickoff/vendors').then(r => r.data),
    vendorStatus: (vendorId) => api.get('/purchase/kickoff/vendor-status', { params: { vendor_id: vendorId } }).then(r => r.data),
    // Agenda builder (Meeting.docx §3/§4).
    agenda: {
      list:         (id)          => api.get(`/purchase/kickoff/${id}/agenda`).then(r => r.data),
      create:       (id, data)    => api.post(`/purchase/kickoff/${id}/agenda`, data).then(r => r.data),
      update:       (id, aid, d)  => api.put(`/purchase/kickoff/${id}/agenda/${aid}`, d).then(r => r.data),
      remove:       (id, aid)     => api.delete(`/purchase/kickoff/${id}/agenda/${aid}`).then(r => r.data),
      loadTemplate: (id)          => api.post(`/purchase/kickoff/${id}/agenda/load-template`).then(r => r.data),
      copyPrevious: (id)          => api.post(`/purchase/kickoff/${id}/agenda/copy-previous`).then(r => r.data),
    },
    // Configurable meeting-type catalogue (kickoff is one type) — §9/§39.
    meetingTypes: ()      => api.get('/purchase/meeting-types').then(r => r.data),
    get:    (id)          => api.get(`/purchase/kickoff/${id}`).then(r => r.data),
    create: (data)        => api.post('/purchase/kickoff', data).then(r => r.data),
    update: (id, data)    => api.put(`/purchase/kickoff/${id}`, data).then(r => r.data),
    transition: (id, data) => api.post(`/purchase/kickoff/${id}/transition`, data).then(r => r.data),
    // Post-meeting attendance — [{ id, attended }]. Audit-logged server-side.
    attendance: (id, rows) => api.patch(`/purchase/kickoff/${id}/attendance`, { rows }).then(r => r.data),
    // Manual reminder — email is a real send; whatsapp/sms are queued stubs.
    remind: (id)          => api.post(`/purchase/kickoff/${id}/remind`).then(r => r.data),
    generateMom: (id)     => api.post(`/purchase/kickoff/${id}/mom/generate`).then(r => r.data),
    uploadMom: (id, file) => {
      const fd = new FormData()
      fd.append('file', file)
      return upload(`/purchase/kickoff/${id}/mom`, fd)
    },
    // Stored MOM PDF as a blob for inline view / download.
    momBlob: (id) => api.get(`/purchase/kickoff/${id}/mom`, { responseType: 'blob' }).then(r => r.data),
    // Labelled supporting documents (multiple upload). actionItemId scopes to an action's evidence.
    documents:       (id, actionItemId) => api.get(`/purchase/kickoff/${id}/documents`, { params: actionItemId ? { action_item_id: actionItemId } : {} }).then(r => r.data?.data ?? r.data),
    uploadDocuments: (id, files, labels, actionItemId) => {
      const fd = new FormData()
      files.forEach((f) => fd.append('files[]', f))
      labels.forEach((l) => fd.append('labels[]', l ?? ''))
      if (actionItemId) fd.append('action_item_id', actionItemId)
      return upload(`/purchase/kickoff/${id}/documents`, fd)
    },
    deleteDocument:  (id, docId) => api.delete(`/purchase/kickoff/${id}/documents/${docId}`).then(r => r.data),
    documentBlob:    (id, docId) => api.get(`/purchase/kickoff/${id}/documents/${docId}/download`, { responseType: 'blob' }).then(r => r.data),
    // MOM approval lifecycle (Draft → Pending Organizer → Pending Chairperson → Approved → Distributed).
    momSubmit: (id)        => api.post(`/purchase/kickoff/${id}/mom/submit`).then(r => r.data),
    momDecide: (id, data)  => api.post(`/purchase/kickoff/${id}/mom/decide`, data).then(r => r.data),
    momRevise: (id)        => api.post(`/purchase/kickoff/${id}/mom/revise`).then(r => r.data),
    publish: (id)         => api.post(`/purchase/kickoff/${id}/publish`).then(r => r.data),
    remove:  (id)         => api.delete(`/purchase/kickoff/${id}`).then(r => r.data),
    // MOM action engine — Meeting → Action → Owner → Due → Evidence → Verification → Closure.
    actions: {
      list:   (id)               => api.get(`/purchase/kickoff/${id}/actions`).then(r => r.data),
      create: (id, data)         => api.post(`/purchase/kickoff/${id}/actions`, data).then(r => r.data),
      update: (id, aid, data)    => api.put(`/purchase/kickoff/${id}/actions/${aid}`, data).then(r => r.data),
      // Status progression; pass an evidence File to attach it (multipart).
      progress: (id, aid, data, file) => {
        if (file) {
          const fd = new FormData()
          Object.entries(data || {}).forEach(([k, v]) => { if (v != null) fd.append(k, v) })
          fd.append('evidence', file)
          return upload(`/purchase/kickoff/${id}/actions/${aid}/progress`, fd)
        }
        return api.post(`/purchase/kickoff/${id}/actions/${aid}/progress`, data).then(r => r.data)
      },
      evidenceBlob: (id, aid) => api.get(`/purchase/kickoff/${id}/actions/${aid}/evidence`, { responseType: 'blob' }).then(r => r.data),
      remove: (id, aid)       => api.delete(`/purchase/kickoff/${id}/actions/${aid}`).then(r => r.data),
    },
    // MOM issue register — track to resolution; convert to NCR / CAPA.
    issues: {
      list:    (id)            => api.get(`/purchase/kickoff/${id}/issues`).then(r => r.data),
      create:  (id, data)      => api.post(`/purchase/kickoff/${id}/issues`, data).then(r => r.data),
      update:  (id, iid, data) => api.put(`/purchase/kickoff/${id}/issues/${iid}`, data).then(r => r.data),
      progress: (id, iid, status) => api.post(`/purchase/kickoff/${id}/issues/${iid}/progress`, { status }).then(r => r.data),
      // Escalate an issue — target: 'ncr' | 'capa'.
      convert: (id, iid, target) => api.post(`/purchase/kickoff/${id}/issues/${iid}/convert`, { target }).then(r => r.data),
      remove:  (id, iid)       => api.delete(`/purchase/kickoff/${id}/issues/${iid}`).then(r => r.data),
    },
    // MOM decision register — Active / Superseded / Rescinded.
    decisions: {
      list:   (id)            => api.get(`/purchase/kickoff/${id}/decisions`).then(r => r.data),
      create: (id, data)      => api.post(`/purchase/kickoff/${id}/decisions`, data).then(r => r.data),
      update: (id, did, data) => api.put(`/purchase/kickoff/${id}/decisions/${did}`, data).then(r => r.data),
      remove: (id, did)       => api.delete(`/purchase/kickoff/${id}/decisions/${did}`).then(r => r.data),
    },
  },

  // ── Purchase onboarding — the 6-step wizard (/purchase/onboarding) ───
  // Mirrors tpvApi.onboarding (incl. decisions + kickoff) so the shared wizard works.
  // ── Purchase workforce (admin/staff) ──────────────────────────────────
  // Tenant-scoped server-side: vendor_id here only FILTERS, it never authorises.
  // Badge activation is role:admin on the backend — the UI hides the button for
  // staff, and the endpoint refuses them regardless.
  workforce: {
    workers:  (params = {}) => api.get('/purchase/workforce/workers', { params }).then(r => r.data),
    worker:   (id)          => api.get(`/purchase/workforce/workers/${id}`).then(r => r.data),
    ppe:      (id)          => api.get(`/purchase/workforce/workers/${id}/ppe`).then(r => r.data),
    gate:     (id)          => api.get(`/purchase/workforce/workers/${id}/gate`).then(r => r.data),
    activate: (id, data = {}) => api.post(`/purchase/workforce/workers/${id}/activate`, data).then(r => r.data),
    // Worker lifecycle (admin) — suspend/reinstate/terminate withhold or restore site access.
    suspend:   (id, reason)  => api.post(`/purchase/workforce/workers/${id}/suspend`, { reason }).then(r => r.data),
    reinstate: (id)          => api.post(`/purchase/workforce/workers/${id}/reinstate`).then(r => r.data),
    terminate: (id, reason)  => api.post(`/purchase/workforce/workers/${id}/terminate`, { reason }).then(r => r.data),
    returnPpe: (issueId, data = {}) => api.post(`/purchase/workforce/ppe/issues/${issueId}/return`, data).then(r => r.data),
    // Vendor-detail Medical / Training tabs. Purchase keeps these NORMALISED
    // (one-to-many), so they list the records themselves — not one row per
    // worker the way TPV's single wide table allows.
    medicals:  (vendorId) => api.get('/purchase/workforce/medicals', { params: { vendor_id: vendorId } }).then(r => r.data),
    trainings: (vendorId) => api.get('/purchase/workforce/trainings', { params: { vendor_id: vendorId } }).then(r => r.data),

    // Admin-side worker registration — the mirror of TPV's worker wizard, on
    // Purchase's own tables. Staff may add and correct workers and record their
    // medical/induction evidence; ACTIVATION stays admin-only server-side.
    stats:         ()          => api.get('/purchase/workforce/workers/stats').then(r => r.data),
    createWorker:  (data)      => api.post('/purchase/workforce/workers', data).then(r => r.data),
    updateWorker:  (id, data)  => api.put(`/purchase/workforce/workers/${id}`, data).then(r => r.data),
    deleteWorker:  (id)        => api.delete(`/purchase/workforce/workers/${id}`).then(r => r.data),
    saveMedical:   (id, data)  => api.post(`/purchase/workforce/workers/${id}/medical`, data).then(r => r.data),
    // Step 3 needs BOTH — a worker with an induction but no training never
    // clears the step and so can never be badged.
    saveTraining:  (id, data)  => api.post(`/purchase/workforce/workers/${id}/training`, data).then(r => r.data),
    saveInduction: (id, data)  => api.post(`/purchase/workforce/workers/${id}/induction`, data).then(r => r.data),
    badge:         (id)        => api.get(`/purchase/workforce/workers/${id}/badge`).then(r => r.data),
    // PPE from the admin side — the catalogue of kit, and issuing it at the gate.
    // Both existed in the service but were reachable only from the vendor portal.
    ppeCatalogue:  ()          => api.get('/purchase/workforce/ppe/catalogue').then(r => r.data?.data ?? r.data),
    issuePpe:      (id, data)  => api.post(`/purchase/workforce/workers/${id}/ppe/issue`, data).then(r => r.data),
  },

  // ── Site gate ─────────────────────────────────────────────────────────
  // Purchase could decide whether a worker may enter but recorded nothing when
  // it did, so it had no gate log and no attendance. The decision and its
  // reasons are stored per scan, never re-derived on read.
  gate: {
    stats:      (date)        => api.get('/purchase/gate/stats', { params: date ? { date } : {} }).then(r => r.data),
    log:        (params = {}) => api.get('/purchase/gate/log', { params }).then(r => r.data?.data ?? r.data),
    onSite:     (date)        => api.get('/purchase/gate/on-site', { params: date ? { date } : {} }).then(r => r.data?.data ?? r.data),
    scan:       (workerId, data = {}) => api.post(`/purchase/gate/workers/${workerId}/scan`, data).then(r => r.data),
    attendance: (workerId, params = {}) => api.get(`/purchase/gate/workers/${workerId}/attendance`, { params }).then(r => r.data),
    // Non-person crossings (TPV §20) — equipment / material / vehicle / visitor.
    events:      (params = {}) => api.get('/purchase/gate/events', { params }).then(r => r.data?.data ?? r.data),
    storeEvent:  (data)        => api.post('/purchase/gate/events', data).then(r => r.data),
  },

  // ── Workforce Competency & Skill Matrix (mirror of TPV §15) ─────────────
  // "No Competency, No Work" — records of what a worker holds; the badge gate
  // matches these against the tenant Settings requirement.
  competency: {
    roster:           (params = {})     => api.get('/purchase/workforce/competency', { params }).then(r => r.data),
    worker:           (workerId)        => api.get(`/purchase/workforce/workers/${workerId}/competency`).then(r => r.data),
    addCompetency:    (workerId, data)  => api.post(`/purchase/workforce/workers/${workerId}/competencies`, data).then(r => r.data),
    updateCompetency: (id, data)        => api.put(`/purchase/workforce/competencies/${id}`, data).then(r => r.data),
    deleteCompetency: (id)              => api.delete(`/purchase/workforce/competencies/${id}`).then(r => r.data),
    skillMatrix:      (vendorId)        => api.get(`/purchase/workforce/vendors/${vendorId}/skill-matrix`).then(r => r.data),
  },

  // ── Compliance register (mirror of TPV §21 — purchase_vendor_compliance) ─
  vendorCompliance: {
    roster: ()                  => api.get('/purchase/vendor-compliance').then(r => r.data),
    matrix: (vendorId)          => api.get(`/purchase/vendors/${vendorId}/compliance`).then(r => r.data),
    upsert: (vendorId, data)    => api.post(`/purchase/vendors/${vendorId}/compliance`, data).then(r => r.data),
    delete: (complianceId)      => api.delete(`/purchase/vendor-compliance/${complianceId}`).then(r => r.data),
  },

  // ── Non-Conformance Reports (mirror of TPV §24 — purchase_ncrs) ─────────
  ncrs: {
    list:       (params = {}) => api.get('/purchase/ncrs', { params }).then(r => r.data),
    create:     (data)        => api.post('/purchase/ncrs', data).then(r => r.data),
    update:     (id, data)    => api.put(`/purchase/ncrs/${id}`, data).then(r => r.data),
    transition: (id, data)    => api.post(`/purchase/ncrs/${id}/transition`, data).then(r => r.data),
    delete:     (id)          => api.delete(`/purchase/ncrs/${id}`).then(r => r.data),
  },

  // ── CAPA register (mirror of TPV §25 — purchase_capas) ─────────────────
  capas: {
    list:       (params = {}) => api.get('/purchase/capas', { params }).then(r => r.data),
    create:     (data)        => api.post('/purchase/capas', data).then(r => r.data),
    update:     (id, data)    => api.put(`/purchase/capas/${id}`, data).then(r => r.data),
    transition: (id, data)    => api.post(`/purchase/capas/${id}/transition`, data).then(r => r.data),
    delete:     (id)          => api.delete(`/purchase/capas/${id}`).then(r => r.data),
  },

  // ── HSSE incidents → RCA → CAPA (mirror of TPV Doc_4 Phase 5). Serious/Fatal or
  // stop-work incidents auto-suspend the vendor; close requires RCA + all CAPAs verified. ──
  incidents: {
    list:       (params = {}) => api.get('/purchase/incidents', { params }).then(r => r.data?.data ?? r.data),
    get:        (id)          => api.get(`/purchase/incidents/${id}`).then(r => r.data),
    create:     (data)        => api.post('/purchase/incidents', data).then(r => r.data),
    recordRca:  (id, data)    => api.post(`/purchase/incidents/${id}/rca`, data).then(r => r.data),
    close:      (id)          => api.post(`/purchase/incidents/${id}/close`).then(r => r.data),
    addCapa:    (id, data)    => api.post(`/purchase/incidents/${id}/capas`, data).then(r => r.data),
    updateCapa: (id, capaId, data) => api.patch(`/purchase/incidents/${id}/capas/${capaId}`, data).then(r => r.data),
  },

  // ── Governance analytics (mirror of TPV §33 — distinct from procurement reports) ─
  analytics: {
    get:    (params = {}) => api.get('/purchase/analytics', { params }).then(r => r.data),
    export: (dataset)     => api.get('/purchase/analytics/export', { params: { dataset }, responseType: 'blob' }).then(r => r.data),
  },

  // ── Document Vault (mirror of TPV §30 — read-only aggregator + expiry) ───
  documentVault: {
    list:   (params = {}) => api.get('/purchase/document-vault', { params }).then(r => r.data),
    vendor: (vendorId)    => api.get(`/purchase/vendors/${vendorId}/vault`).then(r => r.data),
  },

  // ── Communications Centre (mirror of TPV §31 — derived alerts + send/log) ─
  communications: {
    get:  (params = {}) => api.get('/purchase/communications', { params }).then(r => r.data),
    send: (data)        => api.post('/purchase/communications/send', data).then(r => r.data),
  },

  // ── Inspections & Audits (mirror of TPV §22 — finding → NCR escalation) ──
  inspections: {
    list:           (params = {}) => api.get('/purchase/inspections', { params }).then(r => r.data),
    get:            (id)          => api.get(`/purchase/inspections/${id}`).then(r => r.data),
    create:         (data)        => api.post('/purchase/inspections', data).then(r => r.data),
    update:         (id, data)    => api.put(`/purchase/inspections/${id}`, data).then(r => r.data),
    delete:         (id)          => api.delete(`/purchase/inspections/${id}`).then(r => r.data),
    addFinding:     (id, data)    => api.post(`/purchase/inspections/${id}/findings`, data).then(r => r.data),
    updateFinding:  (fId, data)   => api.put(`/purchase/inspection-findings/${fId}`, data).then(r => r.data),
    deleteFinding:  (fId)         => api.delete(`/purchase/inspection-findings/${fId}`).then(r => r.data),
    escalateFinding:(fId)         => api.post(`/purchase/inspection-findings/${fId}/escalate`).then(r => r.data),
  },

  // ── Vendor Violations & Strikes (mirror of TPV §26 — points → enforce) ──
  violations: {
    list:      (params = {}) => api.get('/purchase/violations', { params }).then(r => r.data),
    record:    (data)        => api.post('/purchase/violations', data).then(r => r.data),
    update:    (id, data)    => api.put(`/purchase/violations/${id}`, data).then(r => r.data),
    delete:    (id)          => api.delete(`/purchase/violations/${id}`).then(r => r.data),
    escalation:(vendorId)    => api.get(`/purchase/vendors/${vendorId}/violation-escalation`).then(r => r.data),
    enforce:   (vendorId, data) => api.post(`/purchase/vendors/${vendorId}/violation-enforce`, data).then(r => r.data),
  },

  // ── Vendor Performance Index (mirror of TPV §27 — governance-scored) ────
  vpi: {
    roster: ()         => api.get('/purchase/vpi').then(r => r.data),
    vendor: (vendorId) => api.get(`/purchase/vendors/${vendorId}/vpi`).then(r => r.data),
  },

  // ── Renewal & Extension (mirror of TPV §28) ─────────────────────────────
  renewals: {
    list:      (params = {}) => api.get('/purchase/renewals', { params }).then(r => r.data),
    assess:    (vendorId)    => api.get(`/purchase/vendors/${vendorId}/renewal-assessment`).then(r => r.data),
    initiate:  (data)        => api.post('/purchase/renewals', data).then(r => r.data),
    reassess:  (id)          => api.post(`/purchase/renewals/${id}/reassess`).then(r => r.data),
    decide:    (id, data)    => api.post(`/purchase/renewals/${id}/decide`, data).then(r => r.data),
    delete:    (id)          => api.delete(`/purchase/renewals/${id}`).then(r => r.data),
  },

  // ── Offboarding / Closure (mirror of TPV §29) ───────────────────────────
  offboardings: {
    list:            (params = {}) => api.get('/purchase/offboardings', { params }).then(r => r.data),
    get:             (id)          => api.get(`/purchase/offboardings/${id}`).then(r => r.data),
    initiate:        (data)        => api.post('/purchase/offboardings', data).then(r => r.data),
    updateChecklist: (id, checklist) => api.put(`/purchase/offboardings/${id}/checklist`, { checklist }).then(r => r.data),
    complete:        (id, data)    => api.post(`/purchase/offboardings/${id}/complete`, data).then(r => r.data),
    delete:          (id)          => api.delete(`/purchase/offboardings/${id}`).then(r => r.data),
  },

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
    // §10 checklist — WHAT was verified, as against the approval chain's WHO
    // signed. The rule engine picks the item list from the vendor's risk level
    // and category, so a Critical vendor draws a longer list than a routine one.
    checklist:    (id)        => api.get(`/purchase/onboarding/${id}/checklist`).then(r => r.data),
    // Merged server-side, so sending one item never clears the rest.
    setChecklist: (id, state) => api.post(`/purchase/onboarding/${id}/checklist`, { state }).then(r => r.data),
    // The artefact the site gate and the vendor both point at. Lazily generated
    // on first request for approvals that predate the feature.
    workStartLetter: (id) => api.get(`/purchase/onboarding/${id}/work-start-letter`, { responseType: 'blob' }).then(r => r.data),
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
    delete:    (vendorId, id)          => api.delete(`/purchase/vendors/${vendorId}/contacts/${id}`).then(r => r.data),
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
