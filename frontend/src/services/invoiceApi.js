// Invoice CRUD + payments — /api/sales/invoices/*
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const invoiceApi = {
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

  generatePublicLink: (id, expiryDays = 30) =>
    api.post(`/sales/invoices/${id}/public-link`, { expiry_days: expiryDays }).then(r => r.data).catch(handleErr),

  sendPaymentReminder: (id) =>
    api.post(`/sales/invoices/${id}/send-reminder`).then(r => r.data).catch(handleErr),

  sendFeedbackRequest: (id) =>
    api.post(`/sales/invoices/${id}/send-feedback-request`).then(r => r.data).catch(handleErr),
}

// Payments are derived from invoices — no dedicated backend resource yet.
export const paymentApi = {
  /**
   * There is no payments index endpoint — payments are read out of the invoice
   * list. `mode` was being forwarded to /sales/invoices, whose controller reads
   * only status and client_id, so it was silently dropped and the Payments
   * screen's mode chips returned the identical unfiltered list every time.
   *
   * Every payment is already in hand once the invoices are flattened, so the
   * filter belongs here rather than in a query param nothing reads.
   */
  list: ({ mode, ...params } = {}) =>
    api.get('/sales/invoices', { params: { ...params, include_payments: true } })
      .then(r => {
        const rows = r.data.flatMap(inv =>
          (inv.payments || []).map(p => ({ ...p, invoice_number: inv.number, client: inv.client_id })))
        return mode ? rows.filter(p => p.mode === mode) : rows
      })
      .catch(handleErr),
}

export default invoiceApi
