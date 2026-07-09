// Credit note operations — /api/sales/credit-notes/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const creditNoteApi = {
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
}

export default creditNoteApi
