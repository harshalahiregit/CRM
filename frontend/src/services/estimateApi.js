// Estimate CRUD + convert-to-invoice — /api/sales/estimates/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const estimateApi = {
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

  recordPayment: (id, data) =>
    api.post(`/sales/estimates/${id}/payments`, data).then(r => r.data).catch(handleErr),
}

export default estimateApi
