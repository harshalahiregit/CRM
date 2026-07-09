// Invoice CRUD + payments — /api/sales/invoices/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

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
}

// Payments are derived from invoices — no dedicated backend resource yet.
export const paymentApi = {
  list: (params = {}) =>
    api.get('/sales/invoices', { params: { ...params, include_payments: true } })
      .then(r => r.data.flatMap(inv => (inv.payments || []).map(p => ({ ...p, invoice_number: inv.number, client: inv.client_id }))))
      .catch(handleErr),
}

export default invoiceApi
