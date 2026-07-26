// Retainer Invoices CRUD — /api/sales/retainer-invoices/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const retainerInvoiceApi = {
  list: (params = {}) =>
    api.get('/sales/retainer-invoices', { params }).then(r => r.data).catch(handleErr),

  get: (id) =>
    api.get(`/sales/retainer-invoices/${id}`).then(r => r.data).catch(handleErr),

  create: (data) =>
    api.post('/sales/retainer-invoices', data).then(r => r.data).catch(handleErr),

  update: (id, data) =>
    api.put(`/sales/retainer-invoices/${id}`, data).then(r => r.data).catch(handleErr),

  delete: (id) =>
    api.delete(`/sales/retainer-invoices/${id}`).then(r => r.data).catch(handleErr),
}

export default retainerInvoiceApi
