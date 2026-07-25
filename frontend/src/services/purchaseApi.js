/**
 * Purchase Module API Service
 * All HTTP calls to /api/purchase/* and the shared /api/vendors/* master.
 * Mirrors the structure of services/hrApi.js.
 */

import api from '@/lib/api'

/**
 * Fetch a Purchase document PDF (PR / RFQ / PO) as an authenticated blob and
 * either download it or open it in a new tab for printing. A plain <a href>
 * can't carry the Bearer token, so we stream the blob through the api client.
 *   kind: 'requests' | 'rfqs' | 'orders'
 */
export async function downloadPurchasePdf(kind, id, { inline = false, filename } = {}) {
  const res = await api.get(`/purchase/${kind}/${id}/download-pdf`, {
    params: inline ? { inline: 1 } : {},
    responseType: 'blob',
  })
  const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
  if (inline) {
    window.open(url, '_blank')                       // Print → new tab
  } else {
    const a = document.createElement('a')
    a.href = url
    a.download = filename || `${kind}-${id}.pdf`
    document.body.appendChild(a); a.click(); a.remove()
  }
  setTimeout(() => URL.revokeObjectURL(url), 15000)
}

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

  // ── Vendor master (shared with TPV) — used for the vendor picker ─────
  vendors: {
    list: (params = {}) => api.get('/vendors', { params: { engagement: 'purchase', ...params } }).then(r => r.data),
  },
}

export default purchaseApi
