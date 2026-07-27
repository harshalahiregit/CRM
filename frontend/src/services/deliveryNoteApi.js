// Delivery note operations — /api/sales/delivery-notes/*
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const deliveryNoteApi = {
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
}

export default deliveryNoteApi
