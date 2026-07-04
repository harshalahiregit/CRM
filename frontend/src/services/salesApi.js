// Sales & Revenue — Real API Service
// Uses the shared axios instance from @/lib/api (Bearer token auto-attached)
// All calls go to /api/sales/*

import api from '@/lib/api'

// ── Helpers ───────────────────────────────────────────────────────────

const handleErr = (err) => {
  const msg = err?.response?.data?.error
           || err?.response?.data?.message
           || 'Something went wrong'
  throw new Error(msg)
}

// ── API ───────────────────────────────────────────────────────────────

export const salesApi = {

  dashboard: {
    get: () => api.get('/sales/dashboard').then(r => r.data).catch(handleErr),
  },

  proposals: {
    list: (params = {}) =>
      api.get('/sales/proposals', { params }).then(r => r.data).catch(handleErr),

    get: (id) =>
      api.get(`/sales/proposals/${id}`).then(r => r.data).catch(handleErr),

    create: (data) =>
      api.post('/sales/proposals', data).then(r => r.data).catch(handleErr),

    update: (id, data) =>
      api.put(`/sales/proposals/${id}`, data).then(r => r.data).catch(handleErr),

    delete: (id) =>
      api.delete(`/sales/proposals/${id}`).then(r => r.data).catch(handleErr),

    send: (id) =>
      api.patch(`/sales/proposals/${id}/send`).then(r => r.data).catch(handleErr),

    updateStatus: (id, status) =>
      api.patch(`/sales/proposals/${id}/status`, { status }).then(r => r.data).catch(handleErr),
  },

  estimates: {
    list: (params = {}) =>
      api.get('/sales/estimates', { params }).then(r => r.data).catch(handleErr),

    get: (id) =>
      api.get(`/sales/estimates/${id}`).then(r => r.data).catch(handleErr),

    create: (data) =>
      api.post('/sales/estimates', data).then(r => r.data).catch(handleErr),

    update: (id, data) =>
      api.put(`/sales/estimates/${id}`, data).then(r => r.data).catch(handleErr),

    delete: (id) =>
      api.delete(`/sales/estimates/${id}`).then(r => r.data).catch(handleErr),

    send: (id) =>
      api.patch(`/sales/estimates/${id}/send`).then(r => r.data).catch(handleErr),

    convertToInvoice: (id, data = {}) =>
      api.post(`/sales/estimates/${id}/convert-to-invoice`, data).then(r => r.data).catch(handleErr),
  },

  invoices: {
    list: (params = {}) =>
      api.get('/sales/invoices', { params }).then(r => r.data).catch(handleErr),

    get: (id) =>
      api.get(`/sales/invoices/${id}`).then(r => r.data).catch(handleErr),

    create: (data) =>
      api.post('/sales/invoices', data).then(r => r.data).catch(handleErr),

    update: (id, data) =>
      api.put(`/sales/invoices/${id}`, data).then(r => r.data).catch(handleErr),

    delete: (id) =>
      api.delete(`/sales/invoices/${id}`).then(r => r.data).catch(handleErr),

    send: (id) =>
      api.patch(`/sales/invoices/${id}/send`).then(r => r.data).catch(handleErr),

    recordPayment: (id, paymentData) =>
      api.post(`/sales/invoices/${id}/payments`, paymentData).then(r => r.data).catch(handleErr),
  },

  creditNotes: {
    list: (params = {}) =>
      api.get('/sales/credit-notes', { params }).then(r => r.data).catch(handleErr),

    get: (id) =>
      api.get(`/sales/credit-notes/${id}`).then(r => r.data).catch(handleErr),

    create: (data) =>
      api.post('/sales/credit-notes', data).then(r => r.data).catch(handleErr),

    applyToInvoice: (id, invoiceId) =>
      api.post(`/sales/credit-notes/${id}/apply`, { invoice_id: invoiceId }).then(r => r.data).catch(handleErr),

    refund: (id, data) =>
      api.post(`/sales/credit-notes/${id}/refund`, data).then(r => r.data).catch(handleErr),

    void: (id) =>
      api.delete(`/sales/credit-notes/${id}`).then(r => r.data).catch(handleErr),
  },

  deliveryNotes: {
    list: (params = {}) =>
      api.get('/sales/delivery-notes', { params }).then(r => r.data).catch(handleErr),

    get: (id) =>
      api.get(`/sales/delivery-notes/${id}`).then(r => r.data).catch(handleErr),

    create: (data) =>
      api.post('/sales/delivery-notes', data).then(r => r.data).catch(handleErr),

    update: (id, data) =>
      api.put(`/sales/delivery-notes/${id}`, data).then(r => r.data).catch(handleErr),

    markDelivered: (id) =>
      api.patch(`/sales/delivery-notes/${id}/deliver`).then(r => r.data).catch(handleErr),

    delete: (id) =>
      api.delete(`/sales/delivery-notes/${id}`).then(r => r.data).catch(handleErr),
  },

  payments: {
    list: (params = {}) =>
      api.get('/sales/invoices', { params: { ...params, include_payments: true } })
        .then(r => r.data.flatMap(inv => (inv.payments || []).map(p => ({ ...p, invoice_number: inv.number, client: inv.client_id }))))
        .catch(handleErr),
  },

  items: {
    list: (params = {}) =>
      api.get('/sales/items', { params }).then(r => r.data).catch(handleErr),

    get: (id) =>
      api.get(`/sales/items/${id}`).then(r => r.data).catch(handleErr),

    create: (data) =>
      api.post('/sales/items', data).then(r => r.data).catch(handleErr),

    update: (id, data) =>
      api.put(`/sales/items/${id}`, data).then(r => r.data).catch(handleErr),

    delete: (id) =>
      api.delete(`/sales/items/${id}`).then(r => r.data).catch(handleErr),
  },

  // Kept for dropdowns that need a flat client list
  // Replace with a real /contacts endpoint when that module is built
  clients: [],
}
