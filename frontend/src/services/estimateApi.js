// Estimate CRUD + convert-to-invoice — /api/sales/estimates/*
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const estimateApi = {
  convertToProforma: (id) =>
    api.post(`/sales/estimates/${id}/convert-to-proforma`).then(r => r.data).catch(handleErr),

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
